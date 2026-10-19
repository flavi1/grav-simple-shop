<?php

declare(strict_types=1);

namespace Grav\Plugin\SimpleShop\Api;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SimpleShop\Delivery\CarrierConfig;
use Grav\Plugin\SimpleShop\Delivery\ModeEditor;
use Grav\Plugin\SimpleShop\Delivery\Geocoder;
use Grav\Plugin\SimpleShop\Delivery\ShippingConstraints;
use Grav\Plugin\SimpleShop\Delivery\ShippingManager;
use Grav\Plugin\SimpleShop\Delivery\ZoneResolver;
use Grav\Plugin\SimpleShop\Invoicing\InvoiceManager;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * API d'administration de la boutique pour Admin2 (plugin `api`).
 *
 * Toutes les routes sont préfixées par `/simple-shop` (donc
 * `/api/v1/simple-shop/...`) et protégées par les permissions
 * `api.simple-shop.read` (lecture) et `api.simple-shop.write` (modifications),
 * déclarées dans `permissions.yaml`. Le métier reste dans `OrderManager`,
 * `InvoiceManager`, `CarrierConfig` et `CustomerManager` : ce contrôleur ne
 * fait que traduire HTTP <-> ces classes.
 */
class ShopApiController extends AbstractApiController
{
    private const PERM_READ  = 'api.simple-shop.read';
    private const PERM_WRITE = 'api.simple-shop.write';

    /** Format d'une référence de commande (voir CartStorage::toOrder()). */
    private const ORDER_REFERENCE = '/^CMD-\d{8}-[A-F0-9]{6}$/';

    // ------------------------------------------------------------------
    // Commandes
    // ------------------------------------------------------------------

    /** GET /simple-shop/orders — liste filtrée et paginée. */
    public function orders(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $pagination = $this->getPagination($request, 25);
        $result = OrderManager::search($this->orderFilters($request), $pagination['limit'], $pagination['offset']);

        $items = array_map(fn (array $o) => $this->orderSummary($o), $result['items']);

        return ApiResponse::create($items, 200, [], [
            'page'     => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total'    => $result['total'],
        ]);
    }

    /** GET /simple-shop/orders/export — export comptable CSV (mêmes filtres). */
    public function exportOrders(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        return new Response(200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="commandes-' . date('Y-m-d') . '.csv"',
            'Cache-Control'       => 'no-store',
        ], OrderManager::exportCsv($this->orderFilters($request)));
    }

    /** GET /simple-shop/orders/{reference} — détail complet. */
    public function order(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $reference = $this->orderReference($request);
        $order = OrderManager::search(['reference' => $reference])['items'][0] ?? null;
        if ($order === null) {
            throw new NotFoundException("Commande introuvable : {$reference}.");
        }

        // Facture d'une commande de la boutique : créée à la première consultation (comportement historique). Une commande
        // manuelle a ses documents, émis explicitement (DocumentManager).
        $manual = ($order['source'] ?? 'shop') === 'manual';
        $invoice = $manual ? [] : InvoiceManager::getOrCreateInvoice($reference);

        return ApiResponse::create($this->orderSummary($order) + [
            'created_at'      => $order['created_at'],
            'customer'        => $order['customer'],
            'shipping'        => $order['shipping'],
            'billing'         => $order['billing'],
            'items'           => $order['items'],
            'tracking'        => $this->trackingView($reference),
            'history'         => array_map(static fn (array $h) => $h + [
                'new_status_label' => OrderManager::statusLabel((string) $h['new_status']),
            ], OrderManager::getStatusHistory($reference)),
            'statuses'        => OrderManager::STATUSES,
            'payments'        => PaymentLedger::forOrder($reference),
            'payment_methods' => array_combine(PaymentLedger::MANUAL_METHODS, array_map([PaymentLedger::class, 'methodLabel'], PaymentLedger::MANUAL_METHODS)),
            'invoice'         => [
                'number'                => $invoice['invoice_number'] ?? null,
                'ereporting_status'     => $invoice['ereporting_status'] ?? null,
                'ereporting_label'      => InvoiceManager::statusLabel((string) ($invoice['ereporting_status'] ?? '')),
                'ereporting_options'    => InvoiceManager::getManualStatusOptions(),
            ],
        ]);
    }

    /** PATCH /simple-shop/orders/{reference}/status — corps : {"status": "shipped"}. */
    public function updateOrderStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $reference = $this->orderReference($request);
        $status = (string) ($this->getRequestBody($request)['status'] ?? '');

        if (!array_key_exists($status, OrderManager::STATUSES)) {
            throw new ValidationException('Statut invalide.', ['status' => 'Valeurs admises : ' . implode(', ', array_keys(OrderManager::STATUSES))]);
        }
        if (OrderManager::getOrder($reference) === null) {
            throw new NotFoundException("Commande introuvable : {$reference}.");
        }

        OrderManager::updateStatus($reference, $status, (string) $this->getUser($request)->username);

        return ApiResponse::create([
            'order_reference' => $reference,
            'status'          => $status,
            'status_label'    => OrderManager::statusLabel($status),
        ]);
    }

    /**
     * POST /simple-shop/orders/{reference}/payments — paiement reçu hors du site (virement, chèque…) ou remboursement.
     * Corps : {"amount": "120.00", "method": "bank_transfer", "paid_at": "2026-10-19", "refund": false, "external_ref": "", "note": ""}.
     */
    public function addOrderPayment(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $reference = $this->orderReference($request);
        $order = OrderManager::getOrder($reference);
        if ($order === null) {
            throw new NotFoundException("Commande introuvable : {$reference}.");
        }
        $body = $this->getRequestBody($request);
        $errors = [];
        $amount = trim(str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) ($body['amount'] ?? '')));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            $errors['amount'] = 'Montant positif, deux décimales au plus (ex. 120.50).';
        }
        $method = (string) ($body['method'] ?? '');
        if (!in_array($method, PaymentLedger::MANUAL_METHODS, true)) {
            $errors['method'] = 'Moyens admis : ' . implode(', ', PaymentLedger::MANUAL_METHODS) . '.';
        }
        $paidAt = (string) ($body['paid_at'] ?? '');
        if ($paidAt !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt) || strtotime($paidAt) === false || $paidAt > gmdate('Y-m-d', time() + 86400))) {
            $errors['paid_at'] = 'Date au format AAAA-MM-JJ, pas dans le futur.';
        }
        $invoiceId = isset($body['invoice_id']) && $body['invoice_id'] !== '' && $body['invoice_id'] !== null ? (int) $body['invoice_id'] : null;
        if ($invoiceId !== null && !InvoiceManager::belongsToOrder($invoiceId, $reference)) {
            $errors['invoice_id'] = 'Document inconnu pour cette commande.';
        }
        if ($errors) {
            throw new ValidationException('Paiement invalide.', $errors);
        }
        [$units, $dec] = array_pad(explode('.', $amount), 2, '0');
        $cents = (int) $units * 100 + (int) str_pad(substr($dec, 0, 2), 2, '0');

        $result = PaymentLedger::record([
            'order_reference' => $reference,
            'invoice_id'      => $invoiceId,
            'amount_cents'    => !empty($body['refund']) ? -$cents : $cents,
            'currency'        => (string) (($order['currency'] ?? '') ?: 'EUR'),
            'method'          => $method,
            'paid_at'         => $paidAt !== '' ? $paidAt : null,
            'recorded_by'     => (string) $this->getUser($request)->username,
            'external_ref'    => (string) ($body['external_ref'] ?? ''),
            'note'            => (string) ($body['note'] ?? ''),
        ]);

        return ApiResponse::create([
            'payment'    => $result['payment'],
            'created'    => $result['created'],
            'settlement' => PaymentLedger::orderSettlement($order),
        ], $result['created'] ? 201 : 200);
    }

    /** PATCH /simple-shop/orders/{reference}/tracking — corps : {"tracking_number": "6A12345678901"} ("" = effacer). */
    public function updateOrderTracking(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $reference = $this->orderReference($request);
        $number = (string) ($this->getRequestBody($request)['tracking_number'] ?? '');

        if (OrderManager::getOrder($reference) === null) {
            throw new NotFoundException("Commande introuvable : {$reference}.");
        }
        if (!OrderManager::setTracking($reference, $number, (string) $this->getUser($request)->username)) {
            throw new ValidationException('Numéro de suivi invalide.', ['tracking_number' => '4 à 40 caractères : lettres, chiffres, tirets.']);
        }

        return ApiResponse::create(['order_reference' => $reference] + $this->trackingView($reference));
    }

    /** @return array{number: ?string, url: ?string} */
    private function trackingView(string $reference): array
    {
        $n = OrderManager::getTracking($reference);

        return [
            'number' => $n,
            'url'    => $n === null ? null : OrderManager::trackingUrl($n, (array) \Grav\Common\Grav::instance()['config']->get('plugins.simple-shop', [])),
        ];
    }

    /** PATCH /simple-shop/orders/{reference}/invoice-status — corps : {"status": "submitted"}. */
    public function updateInvoiceStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $reference = $this->orderReference($request);
        $status = (string) ($this->getRequestBody($request)['status'] ?? '');

        if (!array_key_exists($status, InvoiceManager::getManualStatusOptions())) {
            throw new ValidationException('Statut e-reporting invalide.', ['status' => 'Valeurs admises : ' . implode(', ', array_keys(InvoiceManager::getManualStatusOptions()))]);
        }
        if (!InvoiceManager::updateEreportingStatus($reference, $status)) {
            throw new NotFoundException("Facture introuvable pour la commande {$reference}.");
        }

        return ApiResponse::create([
            'order_reference'   => $reference,
            'ereporting_status' => $status,
            'ereporting_label'  => InvoiceManager::statusLabel($status),
        ]);
    }

    /** GET /simple-shop/orders/{reference}/invoice — PDF Factur-X. */
    public function invoicePdf(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $reference = $this->orderReference($request);

        try {
            $pdfPath = InvoiceManager::ensurePdfGenerated($reference);
        } catch (\Throwable $e) {
            $this->grav['log']->error("[SimpleShop][API] Facture {$reference} : " . $e->getMessage());
            $pdfPath = null;
        }

        if (!$pdfPath || !is_file($pdfPath)) {
            throw new NotFoundException("Impossible de générer la facture de la commande {$reference}.");
        }

        return new Response(200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . basename($pdfPath) . '"',
            'Cache-Control'       => 'no-store',
        ], (string) file_get_contents($pdfPath));
    }

    // ------------------------------------------------------------------
    // Transporteurs
    // ------------------------------------------------------------------

    /**
     * GET /simple-shop/carriers — tout ce qu'il faut à l'écran « Transporteurs » : modes (manuels et des plugins
     * transporteur), fournisseurs, réglages utiles, texte YAML (mode avancé).
     */
    public function carriers(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $path = $this->carriersPath();
        $config = (array) $this->grav['config']->get('plugins.simple-shop', []);
        $manager = new ShippingManager();

        $providers = [];
        foreach ($manager->getProviders() as $id => $provider) {
            $providers[] = ['id' => $id, 'label' => $provider->getLabel()];
        }

        return ApiResponse::create([
            'yaml'      => is_file($path) ? (string) file_get_contents($path) : "carriers: {}\n",
            'zones'     => array_values(ZoneResolver::allowedZones($config)),
            'all_zones' => CarrierConfig::ZONES,
            'modes'     => array_values(array_map([$this, 'modeView'], $manager->getModes())),
            'providers' => $providers,
            'settings'  => [
                'prices_entered' => ShippingManager::pricesEnteredHt($config) ? 'ht' : 'ttc',
                'packaging'      => ShippingManager::packaging($config),
            ],
            'constraints'   => $manager->getConstraints(),
            'product_types' => $this->productTypes($manager->getConstraints()),
            'tax'       => [
                'vat_rate'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate(),
                'tax_code'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultCode(),
            ],
        ]);
    }

    /**
     * Types de produits (blueprints qui déclarent `shop.price_calculation`), plus ceux cités par les contraintes.
     *
     * @return string[]
     */
    private function productTypes(array $constraints): array
    {
        $types = array_keys(\Grav\Plugin\SimpleShop\Product\ProductTypes::all());
        foreach (\Grav\Plugin\SimpleShop\Product\ProductTypes::errors() as $error) {
            $this->grav['log']->warning('[SimpleShop] ' . $error);
        }
        foreach ($constraints as $c) {
            $types = array_merge($types, $c['product_types']);
        }
        $types = array_values(array_unique(array_filter($types)));
        sort($types);

        return $types;
    }

    /** PUT /simple-shop/carrier-constraints — corps : {"constraints": [...]} ; remplace toutes les contraintes globales. */
    public function saveCarrierConstraints(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $list = $this->getRequestBody($request)['constraints'] ?? null;
        if (!is_array($list)) {
            throw new ValidationException('Le champ « constraints » (liste) est obligatoire.');
        }
        [$clean, $errors] = ShippingConstraints::sanitize($list);
        if ($errors !== []) {
            throw new ValidationException('Contraintes invalides.', ['constraints' => $errors]);
        }

        $this->updateCarriersFile(function (array $carriers, ?array &$constraints) use ($clean): array {
            $constraints = ShippingConstraints::toStorage($clean);

            return $carriers;
        });

        return ApiResponse::create(['constraints' => (new ShippingManager())->getConstraints()]);
    }

    /** @return array Vue d'un mode pour l'interface (sans les valeurs par défaut du fournisseur). */
    private function modeView(array $mode): array
    {
        unset($mode['provider_defaults']);

        return $mode;
    }

    /**
     * PUT /simple-shop/carriers/{key} — crée ou modifie un mode.
     * Mode manuel (clé `lettre_suivie`) : toutes les valeurs. Mode d'un plugin (`colissimo.domicile`) : seules les valeurs
     * qui diffèrent de celles du plugin sont enregistrées.
     */
    public function saveCarrierMode(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $key = (string) $this->getRouteParam($request, 'key');
        $manager = new ShippingManager();
        $catalog = $manager->getModes();
        $isProviderMode = strpos($key, '.') !== false;

        if ($isProviderMode && !isset($catalog[$key])) {
            throw new NotFoundException("Mode inconnu : {$key} (plugin transporteur désactivé ?).");
        }
        if (!$isProviderMode && !ModeEditor::isManualKey($key)) {
            throw new ValidationException('Identifiant de mode invalide.', ['key' => 'Minuscules, chiffres et _ (2 à 40 caractères).']);
        }

        [$clean, $errors] = ModeEditor::sanitize($this->getRequestBody($request), $isProviderMode);
        if ($errors !== []) {
            throw new ValidationException('Mode de livraison invalide.', ['mode' => $errors]);
        }
        $warning = null;
        if (($clean['kind'] ?? '') === 'local_pickup') {
            [$clean['geo'], $warning] = $this->locatePickup($clean, $catalog[$key] ?? null);
        }

        $this->updateCarriersFile(function (array $carriers) use ($key, $clean, $catalog, $isProviderMode): array {
            $entry = ModeEditor::toStorage($clean, $isProviderMode ? $catalog[$key]['provider_defaults'] : null);
            if ($entry === []) {
                unset($carriers[$key]);   // identique au plugin : plus de surcharge à conserver
            } else {
                $carriers[$key] = $entry;
            }

            return $carriers;
        });

        $mode = (new ShippingManager())->getModes()[$key] ?? null;

        return ApiResponse::create(['saved' => true, 'warning' => $warning, 'mode' => $mode === null ? null : $this->modeView($mode)]);
    }

    /**
     * Coordonnées du lieu de retrait (géocodage de son adresse). Adresse inchangée : coordonnées précédentes conservées.
     * Adresse introuvable : erreur de saisie. Service injoignable : enregistrement sans coordonnées (mode non proposé), avec un avertissement.
     *
     * @return array{0: ?array, 1: ?string} [geo, avertissement]
     */
    private function locatePickup(array $clean, ?array $previous): array
    {
        $address = $clean['pickup_address'];
        if ($address['street'] === '' || $address['postal_code'] === '' || $address['city'] === '') {
            return [null, null];
        }
        if ($previous !== null && $previous['geo'] !== null && $previous['pickup_address'] == $address) {
            return [$previous['geo'], null];
        }
        try {
            $geo = Geocoder::fromConfig((array) $this->grav['config']->get('plugins.simple-shop', []))
                ->geocodeAddress($address['street'], $address['postal_code'], $address['city']);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('[SimpleShop][API] Géocodage du lieu de retrait : ' . $e->getMessage());

            return [null, 'Le service de géocodage ne répond pas : adresse enregistrée mais non localisée, le retrait ne sera pas proposé. Réenregistrez plus tard.'];
        }
        if ($geo === null) {
            throw new ValidationException('Adresse introuvable.', ['mode' => ['Le service de géocodage ne trouve pas l’adresse du lieu de retrait : vérifiez la rue, le code postal et la ville.']]);
        }

        return [$geo, null];
    }

    /** DELETE /simple-shop/carriers/{key} — supprime un mode manuel, ou rétablit les valeurs du plugin pour un mode de plugin. */
    public function deleteCarrierMode(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $key = (string) $this->getRouteParam($request, 'key');
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $key)) {
            throw new ValidationException('Identifiant de mode invalide.');
        }

        $existed = false;
        $this->updateCarriersFile(function (array $carriers) use ($key, &$existed): array {
            $existed = isset($carriers[$key]);
            unset($carriers[$key]);

            return $carriers;
        });
        if (!$existed) {
            throw new NotFoundException("Aucun réglage enregistré pour le mode {$key}.");
        }

        $mode = (new ShippingManager())->getModes()[$key] ?? null;

        return ApiResponse::create(['deleted' => true, 'mode' => $mode === null ? null : $this->modeView($mode)]);
    }

    /** POST /simple-shop/carriers/simulate — corps : {weight, thickness, subtotal, zone}. Détail du calcul de chaque mode. */
    public function simulateCarriers(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $body = $this->getRequestBody($request);
        $num = static fn ($v, float $max) => is_numeric($v) && (float) $v >= 0 && (float) $v <= $max ? (float) $v : null;
        $weight = $num($body['weight'] ?? null, 1000000);
        $thickness = $num($body['thickness'] ?? 0, 100000);
        $subtotal = $num(isset($body['subtotal']) ? str_replace(',', '.', (string) $body['subtotal']) : null, 1000000);
        $zone = (string) ($body['zone'] ?? 'FR-METRO');
        if ($weight === null || $thickness === null || $subtotal === null || !in_array($zone, CarrierConfig::ZONES, true)) {
            throw new ValidationException('Paramètres de simulation invalides.', ['simulation' => ['Poids (g), épaisseur (mm), total TTC des produits (€) et zone sont obligatoires.']]);
        }
        // Types d'articles du panier simulé : le montant est attribué à chacun (aucun = article sans type).
        $types = array_values(array_filter((array) ($body['types'] ?? []), static fn ($t) => is_string($t) && preg_match('/^[a-z0-9_.-]{1,60}$/i', $t)));
        $subtotals = [];
        foreach ($types ?: [''] as $t) {
            $subtotals[$t] = $subtotal;
        }

        $config = (array) $this->grav['config']->get('plugins.simple-shop', []);
        $metrics = ['weight' => (int) $weight, 'thickness' => (int) $thickness, 'subtotal' => $subtotal, 'subtotals' => $subtotals];

        // Commune du client (facultative) pour les retraits sur place.
        $notice = null;
        $postcode = trim((string) ($body['postal_code'] ?? ''));
        $city = trim((string) ($body['city'] ?? ''));
        if ($postcode !== '' || $city !== '') {
            try {
                $metrics['destination'] = Geocoder::fromConfig($config)->geocodeTown($postcode, $city);
                $notice = $metrics['destination'] === null ? 'Commune du client introuvable.' : 'Client localisé : ' . $metrics['destination']['label'] . '.';
            } catch (\Throwable $e) {
                $notice = 'Service de géocodage injoignable : distance inconnue.';
            }
        }

        $rows = [];
        $manager = new ShippingManager();
        foreach ($manager->getModes() as $key => $mode) {
            $r = ShippingManager::evaluate($mode, $zone, $metrics, $config, true, $manager->getConstraints());
            $rows[] = [
                'key'      => $key,
                'name'     => $mode['name'],
                'enabled'  => $mode['enabled'],
                'offered'  => $r['quote'] !== null && $mode['enabled'],
                'reason'   => $r['quote'] === null ? $r['reason'] : ($mode['enabled'] ? '' : 'Mode désactivé (non proposé au client).'),
                'quote'    => $r['quote'],
                'pickup'   => $mode['requires_pickup_point'],
            ];
        }

        return ApiResponse::create(['metrics' => $metrics, 'rows' => $rows, 'notice' => $notice]);
    }

    /** POST /simple-shop/carriers/providers/{id}/test — contrôle de connexion du plugin transporteur. */
    public function testCarrierProvider(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $id = (string) $this->getRouteParam($request, 'id');
        $provider = (new ShippingManager())->getProviders()[$id] ?? null;
        if ($provider === null) {
            throw new NotFoundException("Transporteur inconnu : {$id}.");
        }

        try {
            $result = $provider->testConnection();
        } catch (\Throwable $e) {
            $this->grav['log']->error("[SimpleShop][API] Test du transporteur {$id} : " . $e->getMessage());
            $result = ['ok' => false, 'message' => 'Le test a échoué (voir le journal du serveur).'];
        }

        return ApiResponse::create(['id' => $id] + $result);
    }

    /**
     * Lecture-modification-écriture de carriers.yaml sous verrou : le fichier est relu, modifié par `$change`, validé,
     * puis écrit par fichier temporaire + renommage (jamais de fichier tronqué ; ancienne version en .bak).
     *
     * @param callable(array): array $change Reçoit le tableau `carriers` et retourne le nouveau.
     */
    private function updateCarriersFile(callable $change): void
    {
        $path = $this->carriersPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier inaccessible : {$dir}");
        }

        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Verrou de carriers.yaml indisponible.');
        }

        try {
            $current = is_file($path) ? \Symfony\Component\Yaml\Yaml::parseFile($path) : [];
            $carriers = is_array($current['carriers'] ?? null) ? $current['carriers'] : [];
            $constraints = ShippingConstraints::toStorage(ShippingConstraints::normalize($current['constraints'] ?? []));
            $errors = CarrierConfig::save($path, CarrierConfig::dump($change($carriers, $constraints), $constraints));
            if ($errors !== []) {
                throw new ValidationException('Configuration des transporteurs invalide.', ['carriers' => $errors]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** PUT /simple-shop/carriers — corps : {"yaml": "..."} ; validé avant écriture. */
    public function saveCarriers(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $yaml = $this->getRequestBody($request)['yaml'] ?? null;
        if (!is_string($yaml)) {
            throw new ValidationException('Le champ « yaml » est obligatoire.');
        }

        $errors = CarrierConfig::save($this->carriersPath(), $yaml);
        if ($errors !== []) {
            throw new ValidationException('Configuration des transporteurs invalide.', ['yaml' => $errors]);
        }

        return ApiResponse::create(['saved' => true]);
    }

    private function carriersPath(): string
    {
        return $this->grav['locator']->findResource('user://config/plugins/simple-shop/carriers.yaml', true, true);
    }

    // ------------------------------------------------------------------
    // Taxes (réglages complets : Plugins > Simple Shop ; ici : vue d'ensemble et import/export CSV des taux)
    // ------------------------------------------------------------------

    /** GET /simple-shop/taxes — réglages normalisés : types de taxe et leurs taux (en %), type de taxe de chaque type de produit. */
    public function taxes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);
        $s = \Grav\Plugin\SimpleShop\Tax\TaxResolver::settings();
        $types = [];
        foreach (\Grav\Plugin\SimpleShop\Product\ProductTypes::all() as $name => $type) {
            $types[$name] = $s['names'][\Grav\Plugin\SimpleShop\Tax\TaxResolver::rateFor($name, $type->taxCode)] ?? '';
        }
        $shipping = $s['names'][$s['shipping_tax']] ?? $s['shipping_tax'];

        return ApiResponse::create([
            'enabled' => $s['enabled'], 'prices_entered' => $s['prices_entered'], 'based_on' => $s['based_on'], 'shipping_tax' => $shipping,
            'default_type' => $s['names'][$s['default_rate']] ?? '', 'small_business' => $s['small_business'],
            'tax_types' => \Grav\Plugin\SimpleShop\Tax\TaxSettings::nest($s['rates']), 'product_types' => $types,
        ]);
    }

    /**
     * PUT /simple-shop/taxes/types — corps : {"tax_types": [{name, rates: [{rate, country, region, postcodes, label, priority,
     * compound, shipping, category, mention}]}]} (éditeur de Boutique > Taxes, import CSV). Ancien corps accepté :
     * {"rates": [{name, rate, …}]} (une ligne par taux, `name` = type de taxe).
     * Remplace les types de taxe dans user/config/plugins/simple-shop.yaml. Les types encore utilisés (type par défaut,
     * types de produits, livraison) doivent rester présents.
     */
    public function saveTaxTypes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);
        $body = $this->getRequestBody($request);
        $rows = [];
        $errors = [];
        if (is_array($body['tax_types'] ?? null)) {
            foreach (array_values($body['tax_types']) as $i => $type) {
                $name = is_array($type) ? trim((string) ($type['name'] ?? '')) : '';
                if ($name === '') {
                    $errors['type ' . ($i + 1)] = 'nom obligatoire';
                    continue;
                }
                if (!is_array($type['rates'] ?? null) || $type['rates'] === []) {
                    $errors['« ' . $name . ' »'] = 'au moins un taux';
                    continue;
                }
                foreach (array_values($type['rates']) as $j => $rate) {
                    $rows[] = [is_array($rate) ? ['name' => $name] + array_diff_key($rate, ['name' => 1, 'key' => 1, 'class' => 1]) : null, '« ' . $name . ' », taux ' . ($j + 1)];
                }
            }
        } elseif (is_array($body['rates'] ?? null)) {
            foreach (array_values($body['rates']) as $i => $row) {
                $rows[] = [is_array($row) && !isset($row['class']) && !isset($row['key']) ? $row : null, 'ligne ' . ($i + 1)];
            }
        }
        if (($rows === [] && $errors === []) || count($rows) > 5000) {
            throw new ValidationException('Il faut au moins un type de taxe avec un taux (5 000 taux au plus).');
        }
        $clean = [];
        $keys = [];
        $seen = [];
        foreach ($rows as [$row, $where]) {
            $n = $row !== null ? \Grav\Plugin\SimpleShop\Tax\TaxSettings::normalizeRate($row) : null;
            if ($n === null) {
                $errors[$where] = 'taux invalide (nombre de 0 à 99,99)';
                continue;
            }
            $keys[$n['key']] = true;
            $clean[] = $n;
        }
        foreach ($clean as $n) {   // deux noms différents qui donnent la même clé (« Taux réduit » / « taux reduit »)
            if (isset($seen[$n['key']]) && $seen[$n['key']] !== $n['name']) {
                $errors['« ' . $n['name'] . ' »'] = 'trop proche de « ' . $seen[$n['key']] . ' »';
            }
            $seen[$n['key']] ??= $n['name'];
        }
        $s = \Grav\Plugin\SimpleShop\Tax\TaxResolver::settings();
        foreach (array_unique(array_merge([$s['default_rate']], array_values($s['types']), in_array($s['shipping_tax'], \Grav\Plugin\SimpleShop\Tax\TaxSettings::SHIPPING_MODES, true) ? [] : [$s['shipping_tax']])) as $used) {
            if (!isset($keys[$used])) {
                $errors['« ' . ($s['names'][$used] ?? $used) . ' »'] = 'type encore utilisé dans les réglages (type par défaut, types de produits ou livraison) : à garder';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Types de taxe invalides.', $errors);
        }
        $clean = \Grav\Plugin\SimpleShop\Tax\TaxSettings::nest($clean);

        $path = $this->grav['locator']->findResource('user://config/plugins/simple-shop.yaml', true, true);
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Configuration verrouillée.');
        }
        try {
            $data = is_file($path) ? (array) \Grav\Common\Yaml::parse((string) file_get_contents($path)) : ['enabled' => true];
            $data['taxes'] = is_array($data['taxes'] ?? null) ? $data['taxes'] : [];
            $data['taxes']['tax_types'] = $clean;
            unset($data['taxes']['rates'], $data['taxes']['classes']);
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
            file_put_contents($tmp, \Grav\Common\Yaml::dump($data, 10));
            rename($tmp, $path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        \Grav\Plugin\SimpleShop\Tax\TaxResolver::reset();
        $count = array_sum(array_map(static fn ($t) => count($t['rates']), $clean));
        $this->grav['log']->info('[SimpleShop][API] Types de taxe enregistrés par ' . $this->getUser($request)->username . ' : ' . count($clean) . ' types, ' . $count . ' taux');

        return ApiResponse::create(['saved' => true, 'types' => count($clean), 'count' => $count]);
    }

    // ------------------------------------------------------------------
    // Adresses e-mail bannies
    // ------------------------------------------------------------------

    /** GET /simple-shop/bans */
    public function bans(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        return ApiResponse::create(array_values($this->customerManager()->getBannedEmails()));
    }

    /** POST /simple-shop/bans — corps : {"email": "x@y.fr"}. */
    public function ban(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $email = trim((string) ($this->getRequestBody($request)['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Adresse e-mail invalide.', ['email' => 'Format attendu : nom@domaine.fr']);
        }

        $this->customerManager()->ban($email);
        $this->grav['log']->info('[SimpleShop][API] Adresse bannie par ' . $this->getUser($request)->username . ' : ' . $email);

        return ApiResponse::create(array_values($this->customerManager()->getBannedEmails()), 201);
    }

    /** DELETE /simple-shop/bans/{email} */
    public function unban(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $email = rawurldecode((string) $this->getRouteParam($request, 'email'));
        if (!$this->customerManager()->unban($email)) {
            throw new NotFoundException('Cette adresse ne figure pas dans la liste des bannis.');
        }
        $this->grav['log']->info('[SimpleShop][API] Adresse débannie par ' . $this->getUser($request)->username . ' : ' . $email);

        return ApiResponse::create(array_values($this->customerManager()->getBannedEmails()));
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    private function customerManager(): CustomerManager
    {
        return new CustomerManager($this->grav['session'], $this->grav);
    }

    private function orderReference(ServerRequestInterface $request): string
    {
        $reference = (string) $this->getRouteParam($request, 'reference');
        if (!preg_match(self::ORDER_REFERENCE, $reference)) {
            throw new ValidationException('Référence de commande invalide.', ['reference' => 'Format attendu : CMD-AAAAMMJJ-XXXXXX']);
        }

        return $reference;
    }

    /** @return array<string,string> */
    private function orderFilters(ServerRequestInterface $request): array
    {
        return array_map('strval', $this->getFilters($request, ['status', 'q', 'date_from', 'date_to']));
    }

    /** Vue résumée d'une commande pour la liste. */
    private function orderSummary(array $order): array
    {
        $customer = $order['customer'];

        return [
            'order_reference' => $order['order_reference'],
            'created_at'      => $order['created_at'],
            'status'          => $order['status'],
            'status_label'    => OrderManager::statusLabel((string) $order['status']),
            'total_amount'    => (float) $order['total_amount'],
            'source'          => (string) (($order['source'] ?? '') ?: 'shop'),
            'settlement'      => $order['settlement'] ?? PaymentLedger::orderSettlement($order),
            'customer_name'   => trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')),
            'customer_email'  => $customer['email'] ?? '',
        ];
    }
}
