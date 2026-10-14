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
            'setup'     => ['books_preset' => $this->booksPresetState($path, $manager->getModes())],
            'legal'     => [
                'book_law'  => \Grav\Plugin\SimpleShop\Delivery\LegalShippingFloor::settings($config),
                'vat_rate'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate(),
                'tax_code'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultCode(),
            ],
        ]);
    }

    /**
     * Préréglage « Livres France » : « pending » (à proposer) tant qu'il n'a été ni appliqué ni écarté,
     * sinon « applied » ou « dismissed » (bloc `setup` de carriers.yaml).
     */
    private function booksPresetState(string $path, array $modes): string
    {
        $setup = [];
        if (is_file($path)) {
            try {
                $setup = (array) (\Symfony\Component\Yaml\Yaml::parseFile($path)['setup'] ?? []);
            } catch (\Throwable $e) {
                $setup = [];
            }
        }
        $state = (string) ($setup['books_preset'] ?? '');
        if (in_array($state, ['applied', 'dismissed'], true)) {
            return $state;
        }

        return isset($modes[ModeEditor::BOOKS_PRESET_KEY]) ? 'applied' : 'pending';
    }

    /**
     * POST /simple-shop/carriers/preset — corps : {"action": "apply"|"dismiss", "disable_others": bool}.
     * « apply » crée le mode forfaitaire « Livres France » (et désactive les autres modes si demandé) ;
     * « dismiss » ne le propose plus.
     */
    public function carriersPreset(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_WRITE);

        $body = $this->getRequestBody($request);
        $action = (string) ($body['action'] ?? '');
        if (!in_array($action, ['apply', 'dismiss'], true)) {
            throw new ValidationException('Action inconnue.', ['action' => ['Attendu : apply ou dismiss.']]);
        }
        $config = (array) $this->grav['config']->get('plugins.simple-shop', []);
        $law = \Grav\Plugin\SimpleShop\Delivery\LegalShippingFloor::settings($config);
        if ($action === 'apply' && !$law['enabled']) {
            throw new ValidationException('Préréglage impossible.', ['preset' => [
                'Le plancher légal est désactivé (Plugins > Simple Shop > Livraison) : ce forfait rendrait la livraison gratuite.',
            ]]);
        }
        $disableOthers = $action === 'apply' && filter_var($body['disable_others'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $modes = (new ShippingManager())->getModes();

        $this->updateCarriersFile(function (array $carriers, array &$setup) use ($action, $law, $disableOthers, $modes): array {
            $setup['books_preset'] = $action === 'apply' ? 'applied' : 'dismissed';
            if ($action !== 'apply') {
                return $carriers;
            }
            $carriers[ModeEditor::BOOKS_PRESET_KEY] = ModeEditor::booksPreset($law);
            if ($disableOthers) {
                foreach ($modes as $key => $mode) {
                    if ($key !== ModeEditor::BOOKS_PRESET_KEY && $mode['enabled']) {
                        $carriers[$key] = ['enabled' => false] + (is_array($carriers[$key] ?? null) ? $carriers[$key] : []);
                        $carriers[$key]['enabled'] = false;
                    }
                }
            }

            return $carriers;
        });

        return ApiResponse::create(['books_preset' => $action === 'apply' ? 'applied' : 'dismissed', 'key' => ModeEditor::BOOKS_PRESET_KEY]);
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

        return ApiResponse::create(['saved' => true, 'mode' => $mode === null ? null : $this->modeView($mode)]);
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
            throw new ValidationException('Paramètres de simulation invalides.', ['simulation' => ['Poids (g), épaisseur (mm), montant des livres (€ TTC) et zone sont obligatoires.']]);
        }

        $config = (array) $this->grav['config']->get('plugins.simple-shop', []);
        $metrics = ['weight' => (int) $weight, 'thickness' => (int) $thickness, 'subtotal' => $subtotal];

        $rows = [];
        foreach ((new ShippingManager())->getModes() as $key => $mode) {
            $r = ShippingManager::evaluate($mode, $zone, $metrics, $config, true);
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

        return ApiResponse::create(['metrics' => $metrics, 'rows' => $rows]);
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
            $setup = is_array($current['setup'] ?? null) ? $current['setup'] : [];
            $errors = CarrierConfig::save($path, CarrierConfig::dump($change($carriers, $setup), $setup));
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
