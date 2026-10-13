<?php

declare(strict_types=1);

namespace Grav\Plugin\SimpleShop\Api;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SimpleShop\Delivery\CarrierConfig;
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

    /** GET /simple-shop/carriers — texte YAML courant. */
    public function carriers(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERM_READ);

        $path = $this->carriersPath();

        return ApiResponse::create([
            'yaml'  => is_file($path) ? (string) file_get_contents($path) : "carriers: {}\n",
            'zones' => CarrierConfig::ZONES,
            'legal' => [
                'book_law'  => \Grav\Plugin\SimpleShop\Delivery\LegalShippingFloor::settings((array) $this->grav['config']->get('plugins.simple-shop', [])),
                'vat_rate'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate(),
                'tax_code'  => \Grav\Plugin\SimpleShop\Services\TaxManager::defaultCode(),
            ],
        ]);
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
