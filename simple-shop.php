<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Plugin\SimpleShop\Controllers\CartController;
use Grav\Plugin\SimpleShop\Controllers\CustomerController;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Invoicing\InvoiceManager;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
use Grav\Plugin\SimpleShop\Config\Routes;
use Grav\Theme\NoJs;

class SimpleShopPlugin extends Plugin
{
    /**
     * Nom de l'action de nonce protégeant le formulaire de changement de
     * statut de commande dans l'espace admin (voir
     * templates/admin/simple-shop-orders.html.twig).
     *
     * @var string
     */
    private const ORDERS_STATUS_NONCE_ACTION = 'simple-shop-orders-status';

    /**
     * Nom de l'action de nonce protégeant le formulaire de changement de
     * statut e-reporting de la facture, dans l'espace admin (voir
     * templates/admin/simple-shop-orders.html.twig et
     * Invoicing\InvoiceManager).
     *
     * @var string
     */
    private const ORDERS_INVOICE_STATUS_NONCE_ACTION = 'simple-shop-orders-invoice-status';

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
            'onMvcRegisterRoutes'      => [['onMvcRegisterRoutes', 0]], // <-- Abonnement au nouvel événement de routage MVC
            'onTwigInitialized'        => [['onTwigInitialized', 0]],
            'onTwigTemplatePaths'      => [['onTwigTemplatePaths', 0]],
            'onTwigSiteVariables'      => [['onTwigSiteVariables', 0]],
            'onAdminMenu'              => [['onAdminMenu', 0]],
            'onAdminTwigTemplatePaths' => [['onAdminTwigTemplatePaths', 0]],
            'onPagesInitialized'       => [['onPagesInitialized', 0]]
        ];
    }

    public function onPluginsInitialized(): void
    {
        $this->grav['session']->init();
        $this->grav['assets']->addCss('plugin://simple-shop/css/cart.css');

        // `onTwigSiteVariables` est déjà abonné dans getSubscribedEvents() :
        // ne PAS le réabonner via enable() (le listener s'exécuterait deux fois).
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
                $routes['newsletter_unsubscribe']   => [CustomerController::class, 'unsubscribeNewsletter'],
            ];

            $router->addMany($routesMap);
        }
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
    }

    public function onAdminMenu()
    {
        $this->grav['admin']->sidebar_main['Simple Shop'] = [
            'route' => 'simple-shop-carriers',
            'icon'  => 'fa-truck',
            'index' => 3
        ];

        $this->grav['admin']->sidebar_main['Simple Shop - Commandes'] = [
            'route' => 'simple-shop-orders',
            'icon'  => 'fa-list-alt',
            'index' => 4
        ];
    }

    public function onAdminTwigTemplatePaths($event)
    {
        $event['paths'] = array_merge($event['paths'], [$this->grav['locator']->findResource('plugin://simple-shop/templates/admin')]);
        return $event;
    }

    private function renderAdminTemplate(string $templateName): void
    {
        $twig = $this->grav['twig'];

        if (!isset($twig->twig_vars['admin'])) {
            $this->grav->fireEvent('onTwigSiteVariables');
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $twig->processTemplate($templateName);
        exit;
    }

    /**
     * Calcule l'URL absolue de base de l'espace admin (ex.
     * "https://exemple.tld/admin"), en tenant compte d'une éventuelle route
     * admin personnalisée (`plugins.admin.route`).
     *
     * Utilisée pour construire le lien de téléchargement de facture (voir
     * bloc `simple-shop-orders` ci-dessous et
     * templates/admin/simple-shop-orders.html.twig).
     *
     * @return string
     */
    private function adminBaseUrl(): string
    {
        $adminRoute = trim((string) $this->grav['config']->get('plugins.admin.route', 'admin'), '/');

        return rtrim($this->grav['uri']->rootUrl(true), '/') . '/' . $adminRoute;
    }

    /**
     * CORRECTIF (v2) : après avoir déjà corrigé le faux événement
     * `onAdminRegisterTemplates` (remplacé par `onPagesInitialized`) et le
     * faux appel `Plugin::getBlueprint()`, la page /admin/simple-shop-carriers
     * (et potentiellement /admin/simple-shop-orders) restait malgré tout en
     * 404 : le code de cette méthode s'exécute bel et bien (confirmé par
     * l'erreur alors visible en ligne 232), mais se contenter de fixer
     * `$admin->template` et les twig_vars ne suffit pas. C'est CE QUI SUIT
     * dans le cycle de la requête qui posait problème : le routage standard
     * de Grav tente ensuite de résoudre une véritable `Page` correspondant à
     * l'URL /admin/simple-shop-carriers, n'en trouve aucune dans
     * user/pages/ (notre route est virtuelle, purement gérée par ce
     * plugin), et renvoie donc 404 — indépendamment de ce qu'on a préparé
     * ici.
     *
     * Le fait de fixer `$admin->template` ne fonctionne QUE pour les vues
     * qu'Admin reconnaît déjà nativement (pages/config/plugins/users/tools...),
     * pas pour une route entièrement personnalisée comme celle-ci.
     *
     * SOLUTION (pattern standard pour les pages admin "virtuelles" dans
     * l'écosystème Grav) : court-circuiter nous-mêmes le pipeline de rendu
     * en affichant directement le gabarit Twig ET en appelant `exit`
     * immédiatement, ICI, dans onPagesInitialized — c'est-à-dire AVANT que
     * Grav ne tente de dispatcher une page réelle et échoue en 404. Ce
     * hook se déclenche de façon fiable une fois que $admin->location est
     * déjà résolu (on utilise cette valeur, plus fiable qu'une comparaison
     * manuelle de $uri->path() qui devrait sinon gérer soi-même les préfixes
     * de langue, slash final, etc.).
     *
     * ⚠️ Point à surveiller : si templates/admin/simple-shop-carriers.html.twig
     * soumet son formulaire via le mécanisme standard `task:save` d'Admin
     * (traité normalement par AdminController::taskSave()), il faudra
     * vérifier que cet enregistrement fonctionne toujours après ce
     * correctif, le court-circuit intervenant tôt dans le cycle de la
     * requête. Si besoin, on basculera ce formulaire vers un traitement
     * POST direct ici, à l'image de ce qui est déjà fait pour
     * simple-shop-orders ci-dessous.
     *
     * FACTURATION (nouveau) : deux ajouts liés à Factur-X / e-reporting (voir
     * CDC-E-REPORTING.md, Invoicing\InvoiceManager) :
     *  - la liste des commandes (`simple_shop_orders`) est désormais enrichie
     *    d'une clé `invoice` par commande (numéro de facture, statut
     *    e-reporting), voir templates/admin/simple-shop-orders.html.twig ;
     *  - un second formulaire (statut e-reporting) est traité dans le même
     *    bloc POST que le changement de statut de commande, distingué par la
     *    présence du champ `invoice_ereporting_status` ;
     *  - une route admin dédiée `simple-shop-invoice-download` (ADMIN
     *    UNIQUEMENT, même niveau de protection que simple-shop-orders : accès
     *    déjà filtré par l'authentification du panneau /admin de Grav) génère
     *    (à la demande, avec mise en cache disque) puis sert le PDF Factur-X
     *    d'une commande. Le client ne télécharge JAMAIS sa facture lui-même :
     *    il la reçoit par e-mail à la validation du paiement (voir
     *    CartController::sendOrderEmails() / Services\OrderMailer).
     */
    public function onPagesInitialized()
    {
        if (!$this->isAdmin()) {
            return;
        }

        $admin = $this->grav['admin'];
        
        if ($admin->location === 'simple-shop-carriers') {
            $admin->template = 'simple-shop-carriers';

            try {
                $targetFile = $this->grav['locator']->findResource('user://config/plugins/simple-shop/carriers.yaml', true, true);

                $yamlData = [];
                if (file_exists($targetFile)) {
                    $yamlData = \Symfony\Component\Yaml\Yaml::parseFile($targetFile) ?? [];
                }

                $features = new \Grav\Common\Data\Data(
                    $yamlData,
                    $admin->blueprints('plugins/simple-shop/carriers')
                );

                $this->grav['twig']->twig_vars['admin_carriers'] = $features;

                // CORRECTIF : rendu via renderAdminTemplate() (voir sa
                // documentation ci-dessus) pour éviter à la fois le 404 du
                // routage standard ET l'erreur Twig "merge... got null" sur
                // partials/messages.html.twig (variable `admin` manquante).
                $this->renderAdminTemplate('simple-shop-carriers.html.twig');
            } catch (\Throwable $e) {
                // Filet de sécurité DIAGNOSTIC (même pattern que le bloc
                // "orders" ci-dessous) : évite qu'une erreur ici ne se
                // traduise par une page fatale/blanche côté admin. On rend
                // malgré tout le gabarit (avec des données de repli) pour
                // que le message d'erreur admin soit visible, plutôt que de
                // laisser le routage standard retomber en 404.
                if (isset($this->grav['log'])) {
                    try {
                        $this->grav['log']->error(
                            '[SimpleShop][Admin][Carriers] Erreur lors du chargement de la page Transporteurs : '
                            . $e->getMessage()
                        );
                    } catch (\Throwable $inner) {
                        // Ignoré : filet de sécurité supplémentaire.
                    }
                }
                error_log('[SimpleShop][Admin][Carriers] ERROR: ' . $e->getMessage());

                $admin->setMessage(
                    'Une erreur technique est survenue lors du chargement de la page Transporteurs : '
                    . $e->getMessage(),
                    'error'
                );

                $this->grav['twig']->twig_vars['admin_carriers'] = new \Grav\Common\Data\Data([], null);

                $this->renderAdminTemplate('simple-shop-carriers.html.twig');
            }
        }

        if ($admin->location === 'simple-shop-invoice-download') {
            $orderReference = filter_input(INPUT_GET, 'order_reference', FILTER_DEFAULT);
            $ordersUrl = $this->adminBaseUrl() . '/simple-shop-orders';

            if (!$orderReference) {
                $admin->setMessage('Référence de commande manquante.', 'error');
                $this->grav->redirect($ordersUrl);
                exit;
            }

            try {
                $pdfPath = InvoiceManager::ensurePdfGenerated($orderReference);
            } catch (\Throwable $e) {
                if (isset($this->grav['log'])) {
                    try {
                        $this->grav['log']->error(
                            '[SimpleShop][Admin][Invoice] Erreur lors de la génération de la facture ('
                            . $orderReference . ') : ' . $e->getMessage()
                        );
                    } catch (\Throwable $inner) {
                        // Ignoré : filet de sécurité supplémentaire.
                    }
                }
                error_log('[SimpleShop][Admin][Invoice] ERROR: ' . $e->getMessage());
                $pdfPath = null;
            }

            if (!$pdfPath || !file_exists($pdfPath)) {
                $admin->setMessage(
                    "Impossible de générer la facture pour la commande {$orderReference}.",
                    'error'
                );
                $this->grav->redirect($ordersUrl);
                exit;
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($pdfPath) . '"');
            header('Content-Length: ' . filesize($pdfPath));
            readfile($pdfPath);
            exit;
        }

        if ($admin->location === 'simple-shop-orders') {
            $admin->template = 'simple-shop-orders';

            try {
                // Traitement des formulaires de cette page (POST classique,
                // no-js). Deux formulaires distincts partagent cette même
                // page : changement de statut de commande (existant) et
                // changement de statut e-reporting de la facture (nouveau),
                // distingués par la présence du champ `invoice_ereporting_status`.
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $orderReference = filter_input(INPUT_POST, 'order_reference', FILTER_DEFAULT);

                    if (filter_input(INPUT_POST, 'invoice_ereporting_status', FILTER_DEFAULT) !== null) {
                        // --- Formulaire de statut e-reporting (facture) ---
                        $nonce = filter_input(INPUT_POST, 'simple-shop-invoice-nonce', FILTER_DEFAULT);
                        $newInvoiceStatus = filter_input(INPUT_POST, 'invoice_ereporting_status', FILTER_DEFAULT);

                        if (!$nonce || !Utils::verifyNonce($nonce, self::ORDERS_INVOICE_STATUS_NONCE_ACTION)) {
                            $admin->setMessage('Votre session a expiré, veuillez réessayer.', 'error');
                        } elseif (!$orderReference || !$newInvoiceStatus) {
                            $admin->setMessage(
                                'Requête invalide : référence de commande ou statut e-reporting manquant.',
                                'error'
                            );
                        } else {
                            $updated = InvoiceManager::updateEreportingStatus($orderReference, $newInvoiceStatus);

                            if ($updated) {
                                $admin->setMessage(
                                    "Statut e-reporting de la facture de la commande {$orderReference} mis à jour : "
                                    . InvoiceManager::statusLabel($newInvoiceStatus) . '.',
                                    'info'
                                );
                            } else {
                                $admin->setMessage(
                                    "Impossible de mettre à jour le statut e-reporting de la commande {$orderReference} "
                                    . '(facture introuvable ou statut invalide).',
                                    'error'
                                );
                            }
                        }
                    } else {
                        // --- Formulaire de statut de commande (existant, inchangé) ---
                        $nonce = filter_input(INPUT_POST, 'simple-shop-orders-nonce', FILTER_DEFAULT);
                        $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_DEFAULT);

                        if (!$nonce || !Utils::verifyNonce($nonce, self::ORDERS_STATUS_NONCE_ACTION)) {
                            $admin->setMessage('Votre session a expiré, veuillez réessayer.', 'error');
                        } elseif (!$orderReference || !$newStatus) {
                            $admin->setMessage('Requête invalide : référence de commande ou statut manquant.', 'error');
                        } else {
                            $updated = OrderManager::updateStatus($orderReference, $newStatus);

                            if ($updated) {
                                $admin->setMessage(
                                    'Statut de la commande ' . $orderReference . ' mis à jour : '
                                    . OrderManager::statusLabel($newStatus) . '.',
                                    'info'
                                );
                            } else {
                                $admin->setMessage(
                                    "Impossible de mettre à jour le statut de la commande {$orderReference} "
                                    . '(commande introuvable ou statut invalide).',
                                    'error'
                                );
                            }
                        }
                    }

                    // Pattern PRG (Post/Redirect/Get) : évite la resoumission du
                    // formulaire au rechargement, cohérent avec le reste du site.
                    // Ce redirect() effectue déjà lui-même un exit implicite
                    // (voir Grav::redirect()), donc le routage standard de Grav
                    // n'est jamais atteint pour ce cas.
                    $this->grav->redirect($this->grav['uri']->url());
                    exit;
                }

                $orders = OrderManager::getAllOrders();
                foreach ($orders as &$orderRow) {
                    // Réservation du numéro de facture dès le premier affichage
                    // de la commande dans l'admin (voir doc de
                    // InvoiceManager::getOrCreateInvoice()), indépendamment de
                    // la génération paresseuse du PDF lui-même.
                    $orderRow['invoice'] = InvoiceManager::getOrCreateInvoice($orderRow['order_reference']);
                }
                unset($orderRow);

                $this->grav['twig']->twig_vars['simple_shop_orders'] = $orders;
                $this->grav['twig']->twig_vars['simple_shop_order_statuses'] = OrderManager::STATUSES;
                $this->grav['twig']->twig_vars['simple_shop_orders_nonce'] = Utils::getNonce(self::ORDERS_STATUS_NONCE_ACTION);
                $this->grav['twig']->twig_vars['simple_shop_invoice_statuses'] = InvoiceManager::getManualStatusOptions();
                $this->grav['twig']->twig_vars['simple_shop_invoice_status_nonce'] = Utils::getNonce(self::ORDERS_INVOICE_STATUS_NONCE_ACTION);
                $this->grav['twig']->twig_vars['simple_shop_invoice_download_base'] = $this->adminBaseUrl() . '/simple-shop-invoice-download';

                $this->grav['assets']->addCss('plugin://simple-shop/css/admin-orders.css');

                // CORRECTIF : rendu via renderAdminTemplate(), même raison
                // que pour simple-shop-carriers ci-dessus (voir sa
                // documentation détaillée).
                $this->renderAdminTemplate('simple-shop-orders.html.twig');
            } catch (\Throwable $e) {
                // Filet de sécurité DIAGNOSTIC : sans ce try/catch, une
                // exception ici (ex. base SQLite inaccessible) provoquerait
                // une page blanche/erreur fatale silencieuse côté admin,
                // rendant très difficile de comprendre pourquoi la page
                // "Commandes" semble "absente". On journalise ET on affiche
                // un message admin explicite à la place (rendu direct pour
                // éviter, ici aussi, une retombée en 404).
                if (isset($this->grav['log'])) {
                    try {
                        $this->grav['log']->error(
                            '[SimpleShop][Admin][Orders] Erreur lors du chargement de la page Commandes : '
                            . $e->getMessage()
                        );
                    } catch (\Throwable $inner) {
                        // Ignoré : filet de sécurité supplémentaire.
                    }
                }
                error_log('[SimpleShop][Admin][Orders] ERROR: ' . $e->getMessage());

                $admin->setMessage(
                    'Une erreur technique est survenue lors du chargement de la page Commandes : '
                    . $e->getMessage(),
                    'error'
                );

                $this->grav['twig']->twig_vars['simple_shop_orders'] = [];
                $this->grav['twig']->twig_vars['simple_shop_order_statuses'] = OrderManager::STATUSES;
                $this->grav['twig']->twig_vars['simple_shop_orders_nonce'] = '';
                $this->grav['twig']->twig_vars['simple_shop_invoice_statuses'] = InvoiceManager::getManualStatusOptions();
                $this->grav['twig']->twig_vars['simple_shop_invoice_status_nonce'] = '';
                $this->grav['twig']->twig_vars['simple_shop_invoice_download_base'] = $this->adminBaseUrl() . '/simple-shop-invoice-download';

                $this->renderAdminTemplate('simple-shop-orders.html.twig');
            }
        }
    }
}
