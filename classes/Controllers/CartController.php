<?php
// CartController.php

namespace Grav\Plugin\SimpleShop\Controllers;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Mvc\AbstractController;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Config\Routes;
use Grav\Plugin\SimpleShop\Delivery\ZoneResolver;
use Grav\Plugin\SimpleShop\Gateways\RedirectPaymentGatewayInterface;
use Grav\Plugin\SimpleShop\Delivery\ShippingManager;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Services\AddressFields;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
use Grav\Plugin\SimpleShop\Services\OrderFinalizer;
use Grav\Plugin\SimpleShop\Services\OrderMailer;
use \RocketTheme\Toolbox\Event\Event;


class CartController extends AbstractController
{
    public function __construct(?Grav $grav = null, ?\Grav\Plugin\Mvc\Request $request = null)
    {
        parent::__construct($grav, $request);
        
        $session = $this->grav['session'];
        if (!isset($session->shop_order)) {
            $session->shop_order = new \stdClass();
            $session->shop_order->customer = [];
            $session->shop_order->verified_emails = [];
        }
    }

    /**
     * Récupère le gestionnaire client depuis le conteneur Grav.
     *
     * Permet de réutiliser {@see CustomerManager::handleUserData()} pour la
     * validation/persistance des données client saisies à l'étape 1 du tunnel
     * (formulaire de commande), en cohérence avec
     * `CustomerController::account()` (dette de duplication).
     *
     * @return CustomerManager Le service de gestion du client.
     */
    private function customerManager(): CustomerManager
    {
        return $this->grav['simple_shop_customer'];
    }

    /**
     * Paramètre POST attendu comme chaîne : null s'il est absent ou s'il n'est pas une chaîne (formulaire forgé avec un tableau).
     */
    private function postString(string $key): ?string
    {
        $value = $this->post($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Émet un message flash sur le canal public unifié (public_messages).
     */
    public function addMessage(string $message, string $scope = 'info'): void
    {
        parent::addMessage($message, $scope);
    }

    /**
     * Centralise le rendu Twig via l'API MVC
     */
    private function renderStep(string $stepName, array $additionalVars = []): Response
    {
        $config = $this->pluginConfig('simple-shop');
        $twig = $this->grav['twig'];

        $context = array_merge([
            'customer'           => AddressFields::normalize((array) ($this->grav['session']->shop_order->customer ?? [])),
            'simple_shop_config' => $config,
            'shop_cart'          => $twig->twig_vars['shop_cart'] ?? [],
            'shop_cart_count'    => $twig->twig_vars['shop_cart_count'] ?? 0,
        ], $additionalVars);

        return $this->view("simple-shop/{$stepName}", $context);
    }

    /**
     * Revalide le panier `$cartId` par rapport au stock virtuel courant
     * ({@see CartStorage::revalidateStock()}) et émet un message flash pour
     * chaque ligne ajustée/supprimée.
     *
     * À appeler en tout début de chaque étape du tunnel d'achat
     * (`step1Cart()`, `step2Delivery()`, `step3Payment()`), afin de détecter
     * toute incohérence de stock apparue depuis le dernier ajout au panier
     * (rupture, dépublication, restauration d'un ancien panier après
     * connexion automatique...).
     *
     * @param string $cartId Identifiant du panier à revalider.
     *
     * @return array Les ajustements effectués (vide si le panier était déjà
     *               cohérent) — voir {@see CartStorage::revalidateStock()}.
     */
    private function revalidateCartStock(string $cartId): array
    {
        $adjustments = CartStorage::revalidateStock($cartId);

        foreach ($adjustments as $adjustment) {
            if ($adjustment['available'] <= 0) {
                $this->addMessage(
                    sprintf(
                        "« %s » n'est plus disponible et a été retiré de votre panier.",
                        $adjustment['title']
                    ),
                    'error'
                );
            } else {
                $this->addMessage(
                    sprintf(
                        "Le stock de « %s » a changé : la quantité dans votre panier a été ajustée de %d à %d.",
                        $adjustment['title'],
                        $adjustment['requested'],
                        $adjustment['available']
                    ),
                    'notice'
                );
            }
        }

        return $adjustments;
    }

    /**
     * Total à régler (articles + livraison), recalculé côté serveur.
     *
     * @param array|object $shippingData Livraison choisie (clé `price`).
     */
    private function computeBillingTotal($shippingData): float
    {
        $shippingData = (array) $shippingData;
        $subtotal = 0.0;
        foreach (CartStorage::getCart() as $item) {
            $subtotal += ($item['price'] ?? 0.0) * ($item['quantity'] ?? 0);
        }

        return $subtotal + (float) ($shippingData['price'] ?? 0);
    }

    /**
     * Étape commune après un paiement vérifié : création IDEMPOTENTE de la
     * commande, e-mails (une seule fois), puis redirection serveur vers la
     * confirmation (plus de page auto-soumise en JavaScript).
     *
     * @param array $paymentResult Résultat de la passerelle
     *                             (`payment_reference` rend la création idempotente).
     */
    private function finalizeOrder(array $paymentResult): Response
    {
        $config = $this->grav['config']->get('plugins.simple-shop');
        $reference = (string) ($paymentResult['payment_reference'] ?? '');
        $created = true;

        try {
            $orderNumber = CartStorage::toOrder($reference !== '' ? $reference : null, $created);
        } catch (\Throwable $e) {
            // Paiement vérifié mais commande non enregistrée : cas grave,
            // journalisé avec la référence de paiement pour reprise manuelle.
            $this->grav['log']->error('[SimpleShop] Paiement vérifié mais commande non créée (paiement '
                . ($reference !== '' ? $reference : 'sans référence') . ') : ' . $e->getMessage());
            $this->addMessage(
                "Votre paiement a été reçu mais votre commande n'a pas pu être enregistrée. "
                . "Ne payez pas une seconde fois : contactez-nous"
                . ($reference !== '' ? " en indiquant la référence de paiement {$reference}." : '.'),
                'error'
            );

            return $this->redirect($this->grav['base_url_absolute'] . Routes::get((array) $config, 'cart'), 303);
        }

        // E-mails de commande : uniquement à la création (jamais si la
        // commande existait déjà). Volontairement non bloquant.
        if ($created) {
            $this->sendOrderEmails($orderNumber);
        }

        if (!$created) {
            // Commande déjà créée (webhook arrivé avant le retour du client) :
            // toOrder() n'a pas vidé la session, on le fait ici.
            unset($this->session->shop_order);
        } elseif (isset($this->session->shop_order)) {
            unset($this->session->shop_order->payment_sub_step, $this->session->shop_order->selected_gateway);
        }
        $this->session->shop_last_order = $orderNumber;

        $confirmationRoute = Routes::get((array) $config, 'confirmation');

        return $this->redirect($this->grav['base_url_absolute'] . $confirmationRoute, 303);
    }

    /**
     * Point de retour des paiements avec redirection (page hébergée, 3-D
     * Secure…). Le retour est TOUJOURS vérifié auprès de la passerelle avant
     * de créer la commande (voir RedirectPaymentGatewayInterface).
     */
    public function step3PaymentReturn(): Response
    {
        $config = $this->grav['config']->get('plugins.simple-shop');
        $cartUrl = $this->grav['base_url_absolute'] . Routes::get((array) $config, 'cart');
        $paymentUrl = $this->grav['base_url_absolute'] . Routes::get((array) $config, 'payment');

        $order = $this->session->shop_order ?? null;
        $query = $this->request()->getQueryParams();

        if (!$order || empty($order->selected_gateway)) {
            // Session perdue pendant le paiement (cookie supprimé, expiration).
            $ref = (string) ($query['payment_intent'] ?? $query['session_id'] ?? '');
            $this->grav['log']->error('[SimpleShop] Retour de paiement sans session' . ($ref !== '' ? " (référence {$ref})" : '') . '.');
            $this->addMessage(
                "Votre session a expiré pendant le paiement. Si une somme a été débitée, ne payez pas une seconde fois : "
                . "contactez-nous" . ($ref !== '' ? " en indiquant la référence {$ref}." : '.'),
                'error'
            );

            return $this->redirect($cartUrl, 303);
        }

        $gateways = [];
        $this->grav->fireEvent('onSimpleShopRegisterGateways', new Event(['gateways' => &$gateways]));
        $gateway = $gateways[$order->selected_gateway] ?? null;

        if (!$gateway instanceof RedirectPaymentGatewayInterface) {
            return $this->redirect($paymentUrl, 303);
        }

        $result = $gateway->confirmReturn($query, (array) $order);

        if (!empty($result['success'])) {
            return $this->finalizeOrder($result);
        }

        $order->payment_sub_step = 'billing';
        if (!empty($result['cancelled'])) {
            $this->addMessage("Le paiement a été annulé. Vous pouvez choisir un autre moyen de paiement ou réessayer.", 'notice');
        } else {
            $this->addMessage("Le paiement n'a pas abouti : " . ($result['error'] ?? 'raison inconnue.'), 'error');
        }

        return $this->redirect($paymentUrl, 303);
    }

    /**
     * Envoie les e-mails liés à une commande fraîchement créée : confirmation
     * au client ({@see OrderMailer::sendOrderConfirmation()}) et notification
     * au vendeur ({@see OrderMailer::sendSellerNotification()}).
     *
     * Relit la commande directement depuis les tables `order`/`order_item`
     * (même requête que {@see self::step4ConfirmationOrder()}) plutôt que de
     * réutiliser les données de session, celles-ci étant déjà invalidées par
     * {@see \Grav\Plugin\SimpleShop\Inventory\CartStorage::toOrder()} au
     * moment de l'appel.
     *
     * Volontairement non bloquant : toute erreur (DB, template, envoi SMTP)
     * est journalisée mais n'interrompt jamais le tunnel d'achat, la commande
     * étant déjà persistée avec succès à ce stade.
     *
     * @param string $orderReference Référence de la commande à notifier.
     *
     * @return void
     */
    private function sendOrderEmails(string $orderReference): void
    {
        OrderFinalizer::sendOrderEmails($this->grav, $orderReference);
    }

    public function step1Cart(): Response 
    {
        $cartId = CartStorage::getOrCreateCartId();
        CartStorage::touchCart($cartId);

        // Revalidation systématique du panier par rapport au stock virtuel
        // courant (rupture, dépublication...). Les ajustements éventuels sont
        // signalés par message flash ; si une soumission `process_checkout`
        // est en cours, elle sera interrompue ci-dessous (voir plus bas).
        $stockAdjustments = $this->revalidateCartStock($cartId);

		$db = InventoryManager::getDatabase();

		$config = $this->grav['config']->get('plugins.simple-shop');
		$cartRoute = Routes::get((array) $config, 'cart');
		$emailRoute = Routes::get((array) $config, 'validation');
		$deliveryRoute = Routes::get((array) $config, 'delivery');

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;

		if (!isset($this->session->shop_order)) {
			$this->session->shop_order = new \stdClass();
		}
		if (!isset($this->session->shop_order->customer)) {
			$this->session->shop_order->customer = [];
		}

		if ($this->isPost()) {
			
			$action = $this->postString('action');
			
			if ($action === 'process_checkout') {
				$nonce = $this->postString('nonce');
				if (!$nonce || !Utils::verifyNonce($nonce, 'shop-checkout-action')) {
					$this->addMessage("Votre session de validation a expiré, veuillez réessayer.", 'error');
					return $this->redirect($absoluteCartUrl);
				}
			} else {
				$nonce = $this->postString('nonce');
				if ($action && (!$nonce || !Utils::verifyNonce($nonce, 'shop-cart-action'))) {
					$this->addMessage("Votre session de panier a expiré, veuillez réessayer.", 'error');
					return $this->redirect($absoluteCartUrl);
				}
			}

			CartStorage::touchCart($cartId);

			switch ($action) {
				case 'add_item':
					$productRoute = $this->post('product_route', FILTER_SANITIZE_URL);
					if (!$productRoute) {
						$this->addMessage("Impossible d'identifier le produit.", 'error');
						return $this->redirect($absoluteCartUrl);
					}

					$this->grav['pages']->init(); 
					$page = $this->grav['pages']->dispatch($productRoute);

					if (!$page || !$page->published()) {
						$this->addMessage("Ce produit n'est plus disponible.", 'error');
						return $this->redirect($absoluteCartUrl);
					}

					$virtualStock = InventoryManager::getVirtualStock($productRoute);
					
					$stmtCurrent = $db->prepare("SELECT quantity FROM cart_item WHERE cart_id = :cid AND product_route = :route");
					$stmtCurrent->execute([':cid' => $cartId, ':route' => $productRoute]);
					$currentQty = (int)$stmtCurrent->fetchColumn();

					if ($virtualStock === 0 && $currentQty === 0) {
						$this->addMessage("Désolé, l'article est en rupture de stock.", 'error');
						return $this->redirect($this->grav['base_url_absolute'] . $productRoute);
					}

					$maxAllowable = $virtualStock + $currentQty;
					if ($virtualStock !== -1 && ($currentQty + 1) > $maxAllowable) {
						$this->addMessage("Vous avez réservé la totalité des stocks disponibles.", 'notice');
						return $this->redirect($absoluteCartUrl);
					}

					$db->beginTransaction();
					try {
						$stmt = $db->prepare("
							INSERT INTO cart_item (cart_id, product_route, quantity)
							VALUES (:cart_id, :route, 1)
							ON CONFLICT(cart_id, product_route) DO UPDATE SET quantity = quantity + 1
						");
						$stmt->execute([':cart_id' => $cartId, ':route' => $productRoute]);
						$db->commit();
						$this->addMessage("L'article a été ajouté à votre panier.", 'success');
					} catch (\Exception $e) {
						$db->rollBack();
						$this->addMessage("Une erreur technique est survenue.", 'error');
					}

					return $this->redirect($absoluteCartUrl);

				case 'update_quantity':
					$productRoute = $this->post('product_route', FILTER_SANITIZE_URL);
					$quantity = $this->post('quantity', FILTER_VALIDATE_INT, false);
					
					if ($productRoute && $quantity !== false) {
						if ($quantity <= 0) {
							$stmt = $db->prepare("DELETE FROM cart_item WHERE cart_id = :cid AND product_route = :route");
							$stmt->execute([':cid' => $cartId, ':route' => $productRoute]);
						} else {
							$virtualStock = InventoryManager::getVirtualStock($productRoute);
							$stmtCurrent = $db->prepare("SELECT quantity FROM cart_item WHERE cart_id = :cid AND product_route = :route");
							$stmtCurrent->execute([':cid' => $cartId, ':route' => $productRoute]);
							$currentQty = (int)$stmtCurrent->fetchColumn();

							$maxAllowable = $virtualStock + $currentQty;
							if ($virtualStock !== -1 && $quantity > $maxAllowable) {
								$quantity = $maxAllowable;
							}

							$stmt = $db->prepare("UPDATE cart_item SET quantity = :qty WHERE cart_id = :cid AND product_route = :route");
							$stmt->execute([':qty' => $quantity, ':cid' => $cartId, ':route' => $productRoute]);
						}
					}
					return $this->redirect($absoluteCartUrl);

				case 'remove_item':
					$productRoute = $this->post('product_route', FILTER_SANITIZE_URL);
					if ($productRoute) {
						$stmt = $db->prepare("DELETE FROM cart_item WHERE cart_id = :cid AND product_route = :route");
						$stmt->execute([':cid' => $cartId, ':route' => $productRoute]);
						$this->addMessage("L'article a été retiré de votre panier.", 'notice');
					}
					return $this->redirect($absoluteCartUrl);

				case 'process_checkout':
					// Si le panier vient d'être corrigé (rupture de stock,
					// produit dépublié...), on interrompt la validation :
					// l'utilisateur doit relire son panier corrigé (messages
					// flash déjà émis par revalidateCartStock() ci-dessus)
					// avant de pouvoir soumettre à nouveau.
					if (!empty($stockAdjustments)) {
						return $this->redirect($absoluteCartUrl);
					}

					$acceptCgv = $this->post('accept-cgv', FILTER_DEFAULT);
					if (!$acceptCgv) {
						$this->addMessage("Vous devez accepter les conditions générales de vente pour continuer.", 'error');
						return $this->redirect($absoluteCartUrl);
					}

					// Construction des données client à partir des champs d'identité
					// partagés (dette #11) via CustomerManager::TEXT_FIELDS, complétées
					// par les champs spécifiques au tunnel de commande.
					$customerData = [];
					foreach (CustomerManager::TEXT_FIELDS as $field) {
						$value = $this->post($field, FILTER_DEFAULT);
						$customerData[$field] = is_string($value) ? trim($value) : '';
					}
					$customerData['country']    = $this->post('country', FILTER_DEFAULT) ?: 'FR';
					$customerData['newsletter'] = $this->post('newsletter', FILTER_DEFAULT) ? true : false;
					$customerData['keep-data']  = $this->post('keep-data', FILTER_DEFAULT) ? true : false;
					$customerData['accept-cgv'] = true;

					// Validation + persistance déléguées à CustomerManager (dette #11) :
					// mêmes règles que la page « Modifier mes informations », incluant
					// le blocage d'un e-mail banni (registre banned.list,
					// 'error' === 'banned').
					$result = $this->customerManager()->handleUserData($customerData);

					if (!$result['success']) {
						if ($result['error'] === 'banned') {
							$this->addMessage("Cette adresse e-mail est bannie. Veuillez nous contacter.", 'error');
						} elseif ($result['error'] === 'unsupported_zone') {
							$this->addMessage(ZoneResolver::unsupportedMessage((array) $this->grav['config']->get('plugins.simple-shop', [])), 'error');
						} else {
							$this->addMessage("Veuillez remplir correctement tous les champs obligatoires (*).", 'error');
						}
						return $this->redirect($absoluteCartUrl);
					}

					// Décision de redirection factorisée (voir
					// CustomerManager::resolvePostSaveDestination()), partagée
					// avec CustomerController::account() sans duplication du
					// raisonnement.
					//
					// ⚠️ DÉCISION UX (friction minimale, voir échanges de
					// conception) : `$alwaysVerify` est désormais `false` ICI
					// AUSSI (auparavant `true`, ce qui forçait un aller-retour
					// systématique par l'étape de vérification e-mail — avec
					// un clic "Continuer" — même quand l'e-mail était déjà
					// vérifié et inchangé). Si l'e-mail est déjà vérifié et
					// n'a pas changé, on va donc DIRECTEMENT à l'étape
					// suivante (livraison), sans aucun détour ni clic
					// supplémentaire.
					$destination = $this->customerManager()->resolvePostSaveDestination(
						$result['email_changed'],
						$this->grav['base_url_absolute'] . $deliveryRoute,
						$this->grav['base_url_absolute'] . $emailRoute,
						false
					);

					return $this->redirect($destination['url']);
			}
		}

		$twig = $this->grav['twig'];
		$twig->twig_vars['customer'] = AddressFields::normalize((array) ($this->session->shop_order->customer ?? []));
		$twig->twig_vars['simple_shop_config'] = $config;


        return $this->renderStep('step1cart');
    }

    public function step2Delivery(): Response 
    {
		$config = $this->grav['config']->get('plugins.simple-shop');
		$cartRoute = Routes::get((array) $config, 'cart');
		$emailRoute = Routes::get((array) $config, 'validation');
		$nextStepRoute = Routes::get((array) $config, 'payment');
		$deliveryRoute = Routes::get((array) $config, 'delivery');

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;
		$absoluteDeliveryUrl = $this->grav['base_url_absolute'] . $deliveryRoute;

		// Revalidation systématique du panier par rapport au stock virtuel
		// courant, AVANT tout autre traitement de cette étape : en cas
		// d'ajustement, l'utilisateur est renvoyé au panier pour le relire.
		$cartId = CartStorage::getOrCreateCartId();
		$stockAdjustments = $this->revalidateCartStock($cartId);
		if (!empty($stockAdjustments)) {
			return $this->redirect($absoluteCartUrl);
		}

		$customerData = AddressFields::normalize((array) ($this->session->shop_order->customer ?? []));
		$emailVerified = $this->session->shop_order->email_verified ?? false;

		if (empty($customerData['email']) || empty($customerData['street'])) {
			$this->addMessage("Veuillez d'abord renseigner vos informations de livraison.", 'notice');
			return $this->redirect($absoluteCartUrl);
		}

		// Filet de sécurité (profil ancien, session antérieure à la règle) :
		// livraison uniquement dans les zones desservies.
		if (!ZoneResolver::isDeliverable((array) $customerData, (array) $this->grav['config']->get('plugins.simple-shop', []))) {
			$this->addMessage(ZoneResolver::unsupportedMessage((array) $this->grav['config']->get('plugins.simple-shop', [])), 'error');
			return $this->redirect($absoluteCartUrl);
		}

		if (!$emailVerified) {
			$this->addMessage("Veuillez valider votre adresse e-mail avant de choisir un mode de livraison.", 'notice');
			return $this->redirect($this->grav['base_url_absolute'] . $emailRoute . '?back_url=' . rawurlencode($absoluteDeliveryUrl));
		}

		$cartItems = CartStorage::getCart();
		$shippingManager = new ShippingManager();

		$metrics = $shippingManager->getCartPhysicalMetrics($cartItems);
		if ($shippingManager->needsDestination()) {
			$metrics['destination'] = $this->customerTown((array) $customerData);
		}
		$zone = ZoneResolver::resolve($customerData);
		$availableCarriers = $shippingManager->getAvailableCarriers($zone, $metrics);

		$order = $this->session->shop_order;

		if ($this->isPost()) {
			
			$action = $this->postString('action');
			$nonce = $this->postString('nonce');

			if (in_array($action, ['select_shipping', 'select_pickup', 'change_shipping'], true)
				&& (!$nonce || !Utils::verifyNonce($nonce, 'shop-delivery-action'))) {
				$this->addMessage("Votre session de livraison a expiré, veuillez réessayer.", 'error');
				return $this->redirect($absoluteDeliveryUrl);
			}

			if ($action === 'select_shipping') {
				$selectedCarrierKey = $this->postString('shipping_method');

				if (!$selectedCarrierKey || !isset($availableCarriers[$selectedCarrierKey])) {
					$this->addMessage("Veuillez sélectionner un mode de livraison valide parmi les options proposées.", 'error');
					return $this->redirect($absoluteDeliveryUrl);
				}

				$selected = $availableCarriers[$selectedCarrierKey];

				if (!empty($selected['requires_pickup_point'])) {
					// Mode « point de retrait » : le client choisit le point à la sous-étape suivante (même page).
					// Un choix déjà fait pour ce même mode est conservé tant qu'il reste valable.
					$current = (array) ($order->shipping ?? []);
					$order->pending_shipping = $selected;
					if (($current['key'] ?? null) !== $selectedCarrierKey) {
						unset($order->shipping);
					}
					return $this->redirect($absoluteDeliveryUrl);
				}

				unset($order->pending_shipping, $order->pickup_cache);
				$order->shipping = $selected;

				return $this->redirect($this->grav['base_url_absolute'] . $nextStepRoute);
			}

			if ($action === 'change_shipping') {
				unset($order->pending_shipping);
				return $this->redirect($absoluteDeliveryUrl);
			}

			if ($action === 'select_pickup') {
				$pending = (array) ($order->pending_shipping ?? []);
				$cache = (array) ($order->pickup_cache ?? []);
				$pointId = is_string($this->post('pickup_point')) ? trim($this->post('pickup_point')) : '';

				// Le point doit figurer dans la dernière liste proposée par le transporteur (jamais une valeur libre).
				$point = ($pending && ($cache['mode'] ?? null) === ($pending['key'] ?? null)) ? ($cache['points'][$pointId] ?? null) : null;

				if (!$pending || !isset($availableCarriers[$pending['key'] ?? '']) || $point === null) {
					$this->addMessage("Veuillez choisir un point de retrait dans la liste proposée.", 'error');
					return $this->redirect($absoluteDeliveryUrl);
				}

				$order->shipping = $availableCarriers[$pending['key']] + ['pickup_point' => $point];
				unset($order->pending_shipping, $order->pickup_cache);

				return $this->redirect($this->grav['base_url_absolute'] . $nextStepRoute);
			}
		}

		$twig = $this->grav['twig'];
		$twig->twig_vars['customer'] = $customerData;
		$twig->twig_vars['cart_metrics'] = $metrics;
		$twig->twig_vars['carriers'] = $availableCarriers;
		$twig->twig_vars['current_shipping'] = $order->shipping ?? [];

		// Sous-étape « choix du point de retrait ».
		$pending = (array) ($order->pending_shipping ?? []);
		if ($pending && isset($availableCarriers[$pending['key'] ?? ''])) {
			$pickupZipRaw = $this->get('pickup_zip');
			$pickupZip = is_string($pickupZipRaw) ? $pickupZipRaw : '';
			$pickupCity = is_string($this->get('pickup_city')) ? trim($this->get('pickup_city')) : '';
			$search = [
				'postal-code' => preg_match('/^\d{5}$/', $pickupZip) ? $pickupZip : (string) ($customerData['postal-code'] ?? ''),
				'city'        => $pickupCity !== '' ? mb_substr($pickupCity, 0, 50) : (string) ($customerData['city'] ?? ''),
				'street'      => (string) ($customerData['street'] ?? ''),
				'country'     => 'FR',
			];
			if ($pickupZipRaw !== null) {
				$search['street'] = ''; // recherche sur une autre commune : l'adresse du client ne s'applique plus
			}

			$points = [];
			$error = null;
			try {
				$points = $shippingManager->findPickupPoints($pending['key'], $search, $metrics);
			} catch (\Throwable $e) {
				$this->grav['log']->error('[SimpleShop] Points de retrait indisponibles (' . $pending['key'] . ') : ' . $e->getMessage());
				$error = "Le service des points de retrait est momentanément indisponible. Vous pouvez choisir un autre mode de livraison.";
			}

			$order->pickup_cache = [
				'mode'   => $pending['key'],
				'points' => array_column($points, null, 'id'),
			];

			$twig->twig_vars['pickup'] = [
				'carrier' => $availableCarriers[$pending['key']],
				'points'  => $points,
				'search'  => $search,
				'error'   => $error,
			];
		} else {
			unset($order->pending_shipping);
		}

        return $this->renderStep('step2delivery');
    }

    public function step3Payment(): Response 
    {
		$config = $this->grav['config']->get('plugins.simple-shop');
		$cartRoute = Routes::get((array) $config, 'cart');
		$deliveryRoute = Routes::get((array) $config, 'delivery');
		$emailRoute = Routes::get((array) $config, 'validation');

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;

		// Revalidation systématique du panier par rapport au stock virtuel
		// courant, AVANT tout autre traitement de cette étape : en cas
		// d'ajustement, l'utilisateur est renvoyé au panier pour le relire.
		$cartId = CartStorage::getOrCreateCartId();
		$stockAdjustments = $this->revalidateCartStock($cartId);
		if (!empty($stockAdjustments)) {
			return $this->redirect($absoluteCartUrl);
		}

		// ==========================================
		// TEMPS 0 : VÉRIFICATIONS SILENCIEUSES
		// ==========================================
		$order = $this->session->shop_order;
		$customerData = AddressFields::normalize((array) ($order->customer ?? []));
		$shippingData = $order->shipping ?? [];
		$emailVerified = $order->email_verified ?? false;

		if (empty($customerData['email']) || empty($customerData['street'])) {
			return $this->redirect($absoluteCartUrl);
		}
		if (empty($shippingData['key']) || !isset($shippingData['price'])) {
			return $this->redirect($this->grav['base_url_absolute'] . $deliveryRoute);
		}
		if (!empty($shippingData['requires_pickup_point']) && empty($shippingData['pickup_point']['id'])) {
			return $this->redirect($this->grav['base_url_absolute'] . $deliveryRoute);
		}
		if (!$emailVerified) {
			return $this->redirect($this->grav['base_url_absolute'] . $emailRoute);
		}

		// Récupération des passerelles via l'infrastructure Event de Grav
		$gateways = [];
		$this->grav->fireEvent('onSimpleShopRegisterGateways', new Event(['gateways' => &$gateways]));

		// Fallback d'interface si aucune passerelle n'est enregistrée
		if (empty($gateways)) {
			$this->addMessage("Aucun moyen de paiement disponible. Veuillez contacter l'administrateur.", 'error');
		}

		// État du tunnel de l'étape 3 ('billing' = Temps 1, 'gateway_form' = Temps 2)
		$subStep = $order->payment_sub_step ?? 'billing';

		// ==========================================
		// TRAITEMENT DES FORMULAIRES (POST)
		// ==========================================
		if ($this->isPost()) {
			$action = $this->postString('action');
			$nonce = $this->postString('nonce');

			if (!$nonce || !Utils::verifyNonce($nonce, 'shop-payment-step')) {
				$this->addMessage("Session expirée, veuillez réessayer.", 'error');
				return $this->redirect($this->grav['uri']->route());
			}

			// --- TEMPS 1 -> TEMPS 2 : Soumission Facturation & Choix Gateway ---
			if ($action === 'submit_billing') {
				$selectedGateway = $this->postString('payment_method');
				
				if (empty($selectedGateway) || !isset($gateways[$selectedGateway])) {
					$this->addMessage("Veuillez sélectionner un mode de paiement valide.", 'error');
					return $this->redirect($this->grav['uri']->route());
				}

				// Sauvegarde de l'adresse de facturation
				$order->billing = [
					'name'          => $this->post('billing_name', FILTER_DEFAULT),
					'street'        => $this->post('billing_street', FILTER_DEFAULT),
					'postal-code'      => $this->post('billing_postal_code', FILTER_DEFAULT),
					'city'          => $this->post('billing_city', FILTER_DEFAULT),
					'address-complement' => $this->post('billing_address_complement', FILTER_DEFAULT),
				];
				$order->selected_gateway = $selectedGateway;
				$order->payment_sub_step = 'gateway_form'; // On bascule au Temps 2

				return $this->redirect($this->grav['uri']->route());
			}

			// --- TEMPS 2 -> TEMPS 3 : Exécution finale du paiement ---
			if ($action === 'execute_payment') {
				$currentGatewayKey = $order->selected_gateway ?? null;
				
				if (!$currentGatewayKey || !isset($gateways[$currentGatewayKey])) {
					$order->payment_sub_step = 'billing';
					$this->addMessage("Erreur de passerelle. Veuillez recommencer.", 'error');
					return $this->redirect($this->grav['uri']->route());
				}

				/** @var \Grav\Plugin\SimpleShop\Gateways\PaymentGatewayInterface $gateway */
				$gateway = $gateways[$currentGatewayKey];
				
				// Exécution du Temps 3
				$result = $gateway->processPayment($this->request()->getParsedBody());

				if ($result['success']) {
					return $this->finalizeOrder($result);
				} else {
					// Échec du paiement : retour au Temps 1 avec le message d'erreur
					$this->addMessage("Le paiement a échoué : " . ($result['error'] ?? 'Raison inconnue.'), 'error');
					$order->payment_sub_step = 'billing';
					return $this->redirect($this->grav['uri']->route());
				}
			}

			// --- Paiement hébergé (mode sans JavaScript, ou méthode à redirection) ---
			if ($action === 'start_hosted_payment') {
				$currentGatewayKey = $order->selected_gateway ?? null;
				$gateway = $currentGatewayKey ? ($gateways[$currentGatewayKey] ?? null) : null;

				if (!$gateway instanceof RedirectPaymentGatewayInterface) {
					$order->payment_sub_step = 'billing';
					$this->addMessage("Ce moyen de paiement ne permet pas le paiement sans JavaScript.", 'error');
					return $this->redirect($this->grav['uri']->route());
				}

				// Total recalculé côté serveur au moment du paiement.
				$order->billing_total = $this->computeBillingTotal($shippingData);

				$returnUrl = $this->grav['base_url_absolute']
					. Routes::get((array) $config, 'payment_return');

				$started = $gateway->startHostedPayment((array) $order, $returnUrl);
				if (!empty($started['url'])) {
					return $this->redirect((string) $started['url'], 303);
				}

				$this->addMessage("Impossible de démarrer le paiement : " . ($started['error'] ?? 'erreur inconnue.'), 'error');
				return $this->redirect($this->grav['uri']->route());
			}

			// --- Action : Retour en arrière au Temps 1 ---
			if ($action === 'back_to_billing') {
				$order->payment_sub_step = 'billing';
				return $this->redirect($this->grav['uri']->route());
			}
		}

		// ==========================================
		// PRÉPARATION DU RENDU (GET)
		// ==========================================

		// Initialisation par défaut de la facturation si absente (Copie de la livraison)
		if (empty($order->billing)) {
			$order->billing = [
				'name'          => ($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? ''),
				'street'        => $customerData['street'] ?? '',
				'postal-code'   => $customerData['postal-code'] ?? '',
				'city'          => $customerData['city'] ?? '',
				'address-complement' => $customerData['address-complement'] ?? '',
			];
		}

		// Récupération du panier pour le calcul du total de facturation
		$cart = CartStorage::getCart();
		$order->billing_total = $this->computeBillingTotal($shippingData);

		$gatewayHtml = '';
		if ($subStep === 'gateway_form' && !empty($order->selected_gateway)) {
			$gatewayHtml = $gateways[$order->selected_gateway]->renderPaymentForm((array)$order);
		}

		// Injection des variables dans Twig
		$twig = $this->grav['twig'];
		$twig->twig_vars['cart'] = $cart;
		$twig->twig_vars['customer'] = $customerData;
		$twig->twig_vars['shipping'] = $shippingData;
		$twig->twig_vars['billing'] = AddressFields::normalize((array) $order->billing);
		$twig->twig_vars['gateways'] = $gateways;
		$twig->twig_vars['selected_gateway'] = $order->selected_gateway ?? array_key_first($gateways);
		$twig->twig_vars['sub_step'] = $subStep;
		$twig->twig_vars['gateway_html'] = $gatewayHtml;

		// Routes pour les liens "Modifier" avec fallbacks sécurisés
		$checkoutInfoRoute = Routes::get((array) $config, 'cart');
		$deliveryRoutePath = Routes::get((array) $config, 'delivery');

		$twig->twig_vars['routes'] = [
			'step1' => $this->grav['base_url_absolute'] . $checkoutInfoRoute,
			'step2' => $this->grav['base_url_absolute'] . $deliveryRoutePath
		];

        return $this->renderStep('step3payment');
    }

    public function step4ConfirmationOrder(): Response 
	{
		// La référence vient de la SESSION (posée par finalizeOrder() quand la
		// commande vient d'être créée), jamais de l'URL : sinon n'importe qui
		// connaissant ou devinant une référence verrait nom et adresse du
		// client. Un éventuel paramètre `order_id` n'est accepté que s'il
		// correspond à la commande de cette session.
		$orderReference = (string) ($this->session->shop_last_order ?? '');
		$requested = (string) ($this->post('order_id', FILTER_DEFAULT, $this->get('order_id', FILTER_DEFAULT, '')) ?? '');

		if ($orderReference === '' || ($requested !== '' && $requested !== $orderReference)) {
			$this->addMessage("Aucune commande à afficher pour cette session.", 'error');
			return $this->redirect($this->grav['base_url_absolute'] . Routes::get((array) $this->grav['config']->get('plugins.simple-shop', []), 'cart'));
		}

		$db = InventoryManager::getDatabase();
		$stmt = $db->prepare("SELECT * FROM \"order\" WHERE order_reference = :ref");
		$stmt->execute([':ref' => $orderReference]);
		$orderData = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!$orderData) {
			$this->addMessage("Commande introuvable.", 'error');
			return $this->redirect($this->grav['base_url_absolute'] . Routes::get((array) $this->grav['config']->get('plugins.simple-shop', []), 'cart'));
		}

		$stmtItems = $db->prepare("SELECT * FROM order_item WHERE order_reference = :ref");
		$stmtItems->execute([':ref' => $orderReference]);
		$orderItems = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

		$twig = $this->grav['twig'];
		$twig->twig_vars['order_reference'] = $orderData['order_reference'];
		$twig->twig_vars['order_date'] = $orderData['created_at'];
		$twig->twig_vars['order_total'] = $orderData['total_amount'];
		$twig->twig_vars['customer'] = AddressFields::normalize((array) json_decode($orderData['customer_data'], true));
		$twig->twig_vars['shipping'] = AddressFields::normalize((array) json_decode($orderData['shipping_data'], true));
		$twig->twig_vars['billing'] = AddressFields::normalize((array) json_decode($orderData['billing_data'], true));
		$twig->twig_vars['order_items'] = $orderItems;

        return $this->renderStep('step4confirmationorder');
    }

	/**
	 * Commune du client géocodée (code postal + ville seulement), pour les retraits sur place.
	 * Mise en cache dans la session pour ne pas interroger le service à chaque affichage ; null si inconnue.
	 */
	private function customerTown(array $customer): ?array
	{
		$postcode = trim((string) ($customer['postal-code'] ?? ''));
		$city = trim((string) ($customer['city'] ?? ''));
		$key = md5($postcode . '|' . mb_strtolower($city));
		$cache = $this->session->shop_geo ?? null;
		if (is_array($cache) && ($cache['key'] ?? null) === $key) {
			return $cache['destination'];
		}

		try {
			$destination = \Grav\Plugin\SimpleShop\Delivery\Geocoder::fromConfig((array) $this->grav['config']->get('plugins.simple-shop', []))
				->geocodeTown($postcode, $city);
		} catch (\Throwable $e) {
			// Service injoignable : le retrait sur place n'est simplement pas proposé (nouvel essai au prochain affichage).
			$this->grav['log']->warning('[SimpleShop] Géocodage de la commune du client : ' . $e->getMessage());

			return null;
		}
		$this->session->shop_geo = ['key' => $key, 'destination' => $destination];

		return $destination;
	}
}
