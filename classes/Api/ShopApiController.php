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

        $invoice = InvoiceManager::getOrCreateInvoice($reference);

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
            'customer_name'   => trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')),
            'customer_email'  => $customer['email'] ?? '',
        ];
    }
}
