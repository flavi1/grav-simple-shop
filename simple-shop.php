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
            'onTwigSiteVariables'      => [['onTwigSiteVariables', 0]]
        ];
    }

    public function onPluginsInitialized(): void
    {
        // Listes de l'administration (Plugins > Simple Shop > Taxes) : Grav 2 n'appelle une fonction `data-options@`
        // que si elle est autorisée (Blueprint::isSafeDynamicCall).
        if (method_exists(\Grav\Common\Data\Blueprint::class, 'addAllowedDynamicCallable')) {
            foreach (['classOptions', 'shippingOptions', 'typeOptions'] as $m) {
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

            $routesMap = [
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
            $group->put('/taxes/rates', [$c, 'saveTaxRates']);

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
            'label'     => 'Boutique',
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
            'title'     => 'Boutique',
            'icon'      => 'fa-shopping-cart',
            'page_type' => 'component',
            'actions'   => [],
        ];
    }

    public function addMessage(string $message, string $scope = 'info'): void
    {
        NoJs::addMessage($message, $scope);
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
