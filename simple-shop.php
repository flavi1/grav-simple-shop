<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Plugin\SimpleShop\Controllers\CartController;
use Grav\Plugin\SimpleShop\Controllers\CustomerController;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Invoicing\InvoiceManager;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
use Grav\Plugin\SimpleShop\Services\OrderMailer;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\SimpleShop\Config\Routes;
use Grav\Theme\NoJs;

class SimpleShopPlugin extends Plugin
{
	public function __construct(public $name, Grav $grav, ?Config $config = null) {
		parent::__construct($name, $grav, $config);
		$loader = $grav['loader'];
		$loader->addPsr4('Grav\\Plugin\\SimpleShop\\', __DIR__ . '/classes/');
		require_once __DIR__ . '/vendor/autoload.php';
	}

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized'     => [['onPluginsInitialized', 0]],
            'onPagesInitialized'       => [['onPagesInitialized', 0]],
            'onMvcRegisterRoutes'      => [['onMvcRegisterRoutes', 0]], // <-- Abonnement au nouvel événement de routage MVC
            'onSimpleShopOrderStatusChanged' => [['onOrderStatusChanged', 0]],
            // Administration (Grav 2.0 : plugin `api` + Admin2)
            'onApiRegisterRoutes'      => [['onApiRegisterRoutes', 0]],
            'onApiSidebarItems'        => [['onApiSidebarItems', 0]],
            'onApiPluginPageInfo'      => [['onApiPluginPageInfo', 0]],
            'onTwigInitialized'        => [['onTwigInitialized', 0]],
            'onTwigTemplatePaths'      => [['onTwigTemplatePaths', 0]],
            'onTwigSiteVariables'      => [['onTwigSiteVariables', 0]],
            'onSimpleShopRegisterGateways' => [['registerCoreGateways', 100]],
            'onSimpleShopPaymentRecorded'  => [['onPaymentRecorded', 0]],
        ];
    }

    public function onPluginsInitialized(): void
    {
        // Listes de l'administration (Plugins > Simple Shop > Taxes) : Grav 2 n'appelle une fonction `data-options@`
        // que si elle est autorisée (Blueprint::isSafeDynamicCall).
        if (method_exists(\Grav\Common\Data\Blueprint::class, 'addAllowedDynamicCallable')) {
            foreach (['rateOptions', 'shippingOptions', 'typeOptions'] as $m) {
                try {
                    \Grav\Common\Data\Blueprint::addAllowedDynamicCallable('Grav\\Plugin\\SimpleShop\\Tax\\TaxSettings::' . $m);
                } catch (\Throwable $e) {
                    // Version de Grav sans liste autorisée : les listes gardent leurs options fixes.
                }
            }
        }

        $this->grav['session']->init();
        $this->grav['assets']->addCss('plugin://simple-shop/css/cart.css');

        // `onTwigSiteVariables` est déjà abonné dans getSubscribedEvents() :
        // ne PAS le réabonner via enable() (le listener s'exécuterait deux fois).
    }

    /**
     * Base de saisie des prix (HT/TTC) changée dans la configuration : conversion unique des fiches produit
     * (voir Product\PriceBasisMigration ; sans effet tant que la base ne change pas).
     */
    public function onPagesInitialized(): void
    {
        try {
            $dataDir = $this->grav['locator']->findResource('user://data/simple-shop', true, true);
            $config = (array) $this->config->get('plugins.simple-shop', []);
            $log = $this->grav['log'];
            $done = \Grav\Plugin\SimpleShop\Product\PriceBasisMigration::run(
                $this->grav['pages']->all(),
                (string) $dataDir,
                $config,
                static fn (string $level, string $message) => $log->$level($message)
            );
            if ($done !== null && $done['pages'] > 0) {
                \Grav\Common\Cache::clearCache('cache-only');
            }
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SimpleShop] Conversion des prix des fiches : ' . $e->getMessage());
        }
    }

    /**
     * Enregistre les routes auprès du plugin MVC au moment opportun.
     */
    public function onMvcRegisterRoutes(): void
    {
		$this->grav['simple_shop_customer'] = function($container) {				// necessairement ici : appellé par certains controlleurs
            $manager = new CustomerManager($container['session'], $container);
            if (!$manager->isEmailVerified()) {
                $manager->attemptAutoLogin();
            }
            return $manager;
        };
		
        if (isset($this->grav['mvc_router'])) {
            $config = $this->config();
            $router = $this->grav['mvc_router'];
            $routes = Routes::all($config);

            // Boutique publique désactivée (shop.public: false) : ni catalogue, ni panier, ni tunnel, ni compte client ;
            // seule reste la page des documents envoyés depuis l'administration.
            $public = (bool) ($config['shop']['public'] ?? true);
            $routesMap = !$public ? [] : [
                $routes['cart']                   => [CartController::class, 'step1Cart'],
                $routes['validation']             => [CustomerController::class, 'verifyEmail'],
                $routes['delivery']                => [CartController::class, 'step2Delivery'],
                $routes['payment']                 => [CartController::class, 'step3Payment'],
                $routes['confirmation']             => [CartController::class, 'step4ConfirmationOrder'],
                $routes['payment_return']           => [CartController::class, 'step3PaymentReturn'],
                $routes['identify']                 => [CustomerController::class, 'identify'],
                $routes['account']                  => [CustomerController::class, 'account'],
                $routes['logout']                   => [CustomerController::class, 'logout'],
                $routes['newsletter_unsubscribe']   => [CustomerController::class, 'unsubscribeNewsletter'],
            ];

            if (!empty($config['invoicing']['manual'])) {
                $routesMap[$routes['document']] = [\Grav\Plugin\SimpleShop\Controllers\DocumentController::class, 'show'];
            }

            $router->addMany($routesMap);
        }
    }

    /**
     * Écouteur de `onSimpleShopOrderStatusChanged` : prévient le client par
     * e-mail quand sa commande passe à un statut de la liste
     * `plugins.simple-shop.status_emails` (par défaut : expédiée, annulée,
     * remboursée). Jamais à la création (la confirmation part séparément) et
     * jamais bloquant : une erreur est journalisée, pas propagée.
     */
    public function onOrderStatusChanged(Event $event): void
    {
        if ($event['old_status'] === null) {
            return;
        }

        $enabled = (array) $this->config->get('plugins.simple-shop.status_emails', ['shipped', 'cancelled', 'refunded']);
        $newStatus = (string) $event['new_status'];
        if (!in_array($newStatus, $enabled, true)) {
            return;
        }

        try {
            $reference = (string) $event['order_reference'];
            $order = OrderManager::getOrder($reference);
            if (!$order) {
                return;
            }

            $db = InventoryManager::getDatabase();
            $stmt = $db->prepare('SELECT * FROM order_item WHERE order_reference = :ref ORDER BY id ASC');
            $stmt->execute([':ref' => $reference]);

            (new OrderMailer($this->grav))->sendStatusChange($order, $stmt->fetchAll(\PDO::FETCH_ASSOC), $newStatus);
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SimpleShop] E-mail de changement de statut impossible : ' . $e->getMessage());
        }
    }

    /**
     * Routes de l'API d'administration (`/api/v1/simple-shop/...`), consommées
     * par la page Admin2 du plugin (admin-next/pages/simple-shop.js).
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $c = \Grav\Plugin\SimpleShop\Api\ShopApiController::class;

        $routes->group('/simple-shop', function ($group) use ($c) {
            $group->get('/orders', [$c, 'orders']);
            $group->get('/orders/export', [$c, 'exportOrders']);
            $group->get('/orders/{reference}', [$c, 'order']);
            $group->patch('/orders/{reference}/status', [$c, 'updateOrderStatus']);
            $group->patch('/orders/{reference}/tracking', [$c, 'updateOrderTracking']);
            $group->post('/orders/{reference}/payments', [$c, 'addOrderPayment']);
            $group->post('/orders/{reference}/documents', [$c, 'issueDocument']);
            $group->get('/invoicing', [$c, 'invoicingSettings']);
            $group->get('/customers/lookup', [$c, 'customerLookup']);
            $group->post('/manual-orders', [$c, 'createManualOrder']);
            $group->put('/manual-orders/{reference}', [$c, 'updateManualOrder']);
            $group->get('/manual-orders/{reference}/preview', [$c, 'previewManualOrder']);
            $group->get('/documents', [$c, 'documents']);
            $group->get('/documents/{id}/pdf', [$c, 'documentPdf']);
            $group->patch('/documents/{id}/ereporting', [$c, 'updateDocumentStatus']);
            $group->post('/documents/{id}/send', [$c, 'sendDocument']);
            $group->patch('/orders/{reference}/invoice-status', [$c, 'updateInvoiceStatus']);
            $group->get('/orders/{reference}/invoice', [$c, 'invoicePdf']);

            $group->get('/carriers', [$c, 'carriers']);
            $group->put('/carriers', [$c, 'saveCarriers']);
            $group->post('/carriers/simulate', [$c, 'simulateCarriers']);
            $group->put('/carrier-constraints', [$c, 'saveCarrierConstraints']);
            $group->post('/carriers/providers/{id}/test', [$c, 'testCarrierProvider']);
            $group->put('/carriers/{key}', [$c, 'saveCarrierMode']);
            $group->delete('/carriers/{key}', [$c, 'deleteCarrierMode']);

            $group->get('/taxes', [$c, 'taxes']);
            $group->put('/taxes/types', [$c, 'saveTaxTypes']);
            $group->put('/taxes/rates', [$c, 'saveTaxTypes']);   // ancien nom (import CSV)

            $group->get('/bans', [$c, 'bans']);
            $group->post('/bans', [$c, 'ban']);
            $group->delete('/bans/{email:.+}', [$c, 'unban']);
        });
    }

    /** Entrée « Boutique » dans le menu latéral d'Admin2. */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'];
        $items[] = [
            'id'        => 'simple-shop',
            'plugin'    => 'simple-shop',
            'label'     => 'Ventes',
            'icon'      => 'fa-shopping-cart',
            'route'     => '/plugin/simple-shop',
            'priority'  => 20,
            'badge'     => null,
            'authorize' => 'api.simple-shop.read',
        ];
        $event['items'] = $items;
    }

    /** Définition de la page Admin2 : un composant web (admin-next/pages/simple-shop.js). */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'simple-shop') {
            return;
        }

        $event['definition'] = [
            'id'        => 'simple-shop',
            'plugin'    => 'simple-shop',
            'title'     => 'Ventes',
            'icon'      => 'fa-shopping-cart',
            'page_type' => 'component',
            'actions'   => [],
        ];
    }

    public function addMessage(string $message, string $scope = 'info'): void
    {
        NoJs::addMessage($message, $scope);
    }

    /**
     * Après chaque paiement rattaché à un document : une demande de paiement réglée donne sa facture, puis le client reçoit
     * un reçu (avec la facture acquittée quand tout est réglé). Jamais bloquant.
     */
    public function onPaymentRecorded(Event $event): void
    {
        $payment = (array) ($event['payment'] ?? []);
        if (empty($payment['invoice_id'])) {
            return;
        }
        try {
            $doc = \Grav\Plugin\SimpleShop\Invoicing\DocumentManager::find((int) $payment['invoice_id']);
            if ($doc === null) {
                return;
            }
            $issued = \Grav\Plugin\SimpleShop\Invoicing\DocumentManager::afterPayment((int) $doc['id']);
            if (!\Grav\Plugin\SimpleShop\Services\DocumentMailer::$mute) {
                (new \Grav\Plugin\SimpleShop\Services\DocumentMailer($this->grav))->sendReceipt($issued ?? $doc, $payment);
            }
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SimpleShop] Suites du paiement ' . ($payment['id'] ?? '?') . ' : ' . $e->getMessage());
        }
    }

    /** Passerelles fournies par simple-shop lui-même : virement bancaire (hors ligne), si activé. */
    public function registerCoreGateways(Event $event): void
    {
        if (!$this->grav['config']->get('plugins.simple-shop.payments.bank_transfer.enabled')) {
            return;
        }
        $gateways = $event['gateways'] ?? [];
        $transfer = new \Grav\Plugin\SimpleShop\Gateways\BankTransferGateway();
        $gateways[$transfer->getId()] = $transfer;
        $event['gateways'] = $gateways;
    }

    public function onTwigSiteVariables(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $cartId = CartStorage::getOrCreateCartId();
        $shopCartData = CartStorage::getCartItems($cartId);
        
        $totalItemsCount = 0;
        foreach ($shopCartData as $item) {
            $totalItemsCount += $item['quantity'];
        }

        $config = $this->config();
        $twig = $this->grav['twig'];
        $twig->twig_vars['shop_cart'] = $shopCartData;
        $twig->twig_vars['shop_cart_count'] = $totalItemsCount;
        $twig->twig_vars['shop_routes'] = Routes::all($config);
        $twig->twig_vars['shop_public'] = (bool) ($config['shop']['public'] ?? true);

        if (isset($this->grav['simple_shop_customer'])) {
            /** @var CustomerManager $customerManager */
            $customerManager = $this->grav['simple_shop_customer'];
            $twig->twig_vars['customer'] = $customerManager->getCustomerData();
            $twig->twig_vars['is_verified'] = $customerManager->isEmailVerified();
        } else {
            $twig->twig_vars['customer'] = [];
            $twig->twig_vars['is_verified'] = false;
        }
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigInitialized(): void
    {
        $f = new \Twig\TwigFunction(
            'get_product_shop_data', 
            ['Grav\Plugin\SimpleShop\Inventory\InventoryManager', 'getProductData']
        );
        $this->grav['twig']->twig->addFunction($f);

        // Prix d'un produit tel que calculé par son type (blueprint `shop.price_calculation`) : shop_price(page.route)
        // → Price (unitTtc, unitHt…) ou null ; affichage : {{ shop_price(page.route).unitTtc|shop_money }}.
        $this->grav['twig']->twig->addFunction(new \Twig\TwigFunction(
            'shop_price',
            static fn ($route) => is_string($route) && $route !== '' ? \Grav\Plugin\SimpleShop\Product\Catalog::price($route) : null
        ));
        $this->grav['twig']->twig->addFilter(new \Twig\TwigFilter(
            'shop_money',
            static function ($money): string {
                if (!$money instanceof \Grav\Plugin\SimpleShop\Pricing\Money) {
                    return '';
                }
                $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'];

                return number_format($money->toFloat(), 2, ',', "\u{202F}") . "\u{00A0}" . ($symbols[$money->currency] ?? $money->currency);
            }
        ));

        // Ancienne aide (à éviter) : prix TTC d'un montant saisi dans une fiche, sans passer par le type de produit.
        $this->grav['twig']->twig->addFunction(new \Twig\TwigFunction(
            'shop_price_ttc',
            static function ($value) {
                return ($value === null || $value === '' || !is_numeric($value))
                    ? null
                    : \Grav\Plugin\SimpleShop\Services\TaxManager::toTtc((float) $value);
            }
        ));
    }
}
