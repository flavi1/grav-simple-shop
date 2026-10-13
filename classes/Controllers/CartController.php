<?php
// CartController.php

namespace Grav\Plugin\SimpleShop\Controllers;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Plugin\Mvc\AbstractController;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Delivery\ZoneResolver;
use Grav\Plugin\SimpleShop\Delivery\ShippingManager;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Services\AddressFields;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
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
        try {
            $db = InventoryManager::getDatabase();

            $stmt = $db->prepare("SELECT * FROM \"order\" WHERE order_reference = :ref");
            $stmt->execute([':ref' => $orderReference]);
            $orderData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$orderData) {
                return;
            }

            $stmtItems = $db->prepare("SELECT * FROM order_item WHERE order_reference = :ref");
            $stmtItems->execute([':ref' => $orderReference]);
            $orderItems = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

            $mailer = new OrderMailer($this->grav);
            $mailer->sendOrderConfirmation($orderData, $orderItems);
            $mailer->sendSellerNotification($orderData, $orderItems);
        } catch (\Throwable $e) {
            if (isset($this->grav['log'])) {
                try {
                    $this->grav['log']->error(
                        '[SimpleShop][CartController] Erreur lors de l\'envoi des e-mails de commande '
                        . "({$orderReference}) : " . $e->getMessage()
                    );
                } catch (\Throwable $inner) {
                    // Ignoré : filet de sécurité, ne doit jamais faire échouer le tunnel.
                }
            }
            error_log('[SimpleShop][CartController] ERROR: order emails failed for ' . $orderReference . ': ' . $e->getMessage());
        }
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
		$cartRoute = $config['routes']['cart'] ?? '/cart';
		$emailRoute = $config['routes']['validation'] ?? '/checkout/email';
		$deliveryRoute = $config['routes']['delivery'] ?? '/checkout/delivery';

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;

		if (!isset($this->session->shop_order)) {
			$this->session->shop_order = new \stdClass();
		}
		if (!isset($this->session->shop_order->customer)) {
			$this->session->shop_order->customer = [];
		}

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			
			$action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
			
			if ($action === 'process_checkout') {
				$nonce = filter_input(INPUT_POST, 'nonce', FILTER_DEFAULT);
				if (!$nonce || !Utils::verifyNonce($nonce, 'shop-checkout-action')) {
					$this->addMessage("Votre session de validation a expiré, veuillez réessayer.", 'error');
					$this->grav->redirect($absoluteCartUrl);
					exit;
				}
			} else {
				$nonce = filter_input(INPUT_POST, 'nonce', FILTER_DEFAULT);
				if ($action && (!$nonce || !Utils::verifyNonce($nonce, 'shop-cart-action'))) {
					$this->addMessage("Votre session de panier a expiré, veuillez réessayer.", 'error');
					$this->grav->redirect($absoluteCartUrl);
					exit;
				}
			}

			CartStorage::touchCart($cartId);

			switch ($action) {
				case 'add_item':
					$productRoute = filter_input(INPUT_POST, 'product_route', FILTER_SANITIZE_URL);
					if (!$productRoute) {
						$this->addMessage("Impossible d'identifier le produit.", 'error');
						$this->grav->redirect($absoluteCartUrl);
						exit;
					}

					$this->grav['pages']->init(); 
					$page = $this->grav['pages']->dispatch($productRoute);

					if (!$page || !$page->published()) {
						$this->addMessage("Ce produit n'est plus disponible.", 'error');
						$this->grav->redirect($absoluteCartUrl);
						exit;
					}

					$virtualStock = InventoryManager::getVirtualStock($productRoute);
					
					$stmtCurrent = $db->prepare("SELECT quantity FROM cart_item WHERE cart_id = :cid AND product_route = :route");
					$stmtCurrent->execute([':cid' => $cartId, ':route' => $productRoute]);
					$currentQty = (int)$stmtCurrent->fetchColumn();

					if ($virtualStock === 0 && $currentQty === 0) {
						$this->addMessage("Désolé, l'article est en rupture de stock.", 'error');
						$this->grav->redirect($this->grav['base_url_absolute'] . $productRoute);
						exit;
					}

					$maxAllowable = $virtualStock + $currentQty;
					if ($virtualStock !== -1 && ($currentQty + 1) > $maxAllowable) {
						$this->addMessage("Vous avez réservé la totalité des stocks disponibles.", 'notice');
						$this->grav->redirect($absoluteCartUrl);
						exit;
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

					$this->grav->redirect($absoluteCartUrl);
					exit;

				case 'update_quantity':
					$productRoute = filter_input(INPUT_POST, 'product_route', FILTER_SANITIZE_URL);
					$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
					
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
					$this->grav->redirect($absoluteCartUrl);
					exit;

				case 'remove_item':
					$productRoute = filter_input(INPUT_POST, 'product_route', FILTER_SANITIZE_URL);
					if ($productRoute) {
						$stmt = $db->prepare("DELETE FROM cart_item WHERE cart_id = :cid AND product_route = :route");
						$stmt->execute([':cid' => $cartId, ':route' => $productRoute]);
						$this->addMessage("L'article a été retiré de votre panier.", 'notice');
					}
					$this->grav->redirect($absoluteCartUrl);
					exit;

				case 'process_checkout':
					// Si le panier vient d'être corrigé (rupture de stock,
					// produit dépublié...), on interrompt la validation :
					// l'utilisateur doit relire son panier corrigé (messages
					// flash déjà émis par revalidateCartStock() ci-dessus)
					// avant de pouvoir soumettre à nouveau.
					if (!empty($stockAdjustments)) {
						$this->grav->redirect($absoluteCartUrl);
						exit;
					}

					$acceptCgv = filter_input(INPUT_POST, 'accept-cgv', FILTER_DEFAULT);
					if (!$acceptCgv) {
						$this->addMessage("Vous devez accepter les conditions générales de vente pour continuer.", 'error');
						$this->grav->redirect($absoluteCartUrl);
						exit;
					}

					// Construction des données client à partir des champs d'identité
					// partagés (dette #11) via CustomerManager::TEXT_FIELDS, complétées
					// par les champs spécifiques au tunnel de commande.
					$customerData = [];
					foreach (CustomerManager::TEXT_FIELDS as $field) {
						$customerData[$field] = trim((string) filter_input(INPUT_POST, $field, FILTER_DEFAULT));
					}
					$customerData['country']    = filter_input(INPUT_POST, 'country', FILTER_DEFAULT) ?: 'FR';
					$customerData['newsletter'] = filter_input(INPUT_POST, 'newsletter', FILTER_DEFAULT) ? true : false;
					$customerData['keep-data']  = filter_input(INPUT_POST, 'keep-data', FILTER_DEFAULT) ? true : false;
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
							$this->addMessage(AddressFields::UNSUPPORTED_ZONE_MESSAGE, 'error');
						} else {
							$this->addMessage("Veuillez remplir correctement tous les champs obligatoires (*).", 'error');
						}
						$this->grav->redirect($absoluteCartUrl);
						exit;
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

					$this->grav->redirect($destination['url']);
					exit;
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
		$cartRoute = $config['routes']['cart'] ?? '/cart';
		$emailRoute = $config['routes']['validation'] ?? '/checkout/email';
		$nextStepRoute = $config['routes']['payment'] ?? '/checkout/payment';
		$deliveryRoute = $config['routes']['delivery'] ?? '/checkout/delivery';

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;
		$absoluteDeliveryUrl = $this->grav['base_url_absolute'] . $deliveryRoute;

		// Revalidation systématique du panier par rapport au stock virtuel
		// courant, AVANT tout autre traitement de cette étape : en cas
		// d'ajustement, l'utilisateur est renvoyé au panier pour le relire.
		$cartId = CartStorage::getOrCreateCartId();
		$stockAdjustments = $this->revalidateCartStock($cartId);
		if (!empty($stockAdjustments)) {
			$this->grav->redirect($absoluteCartUrl);
			exit;
		}

		$customerData = AddressFields::normalize((array) ($this->session->shop_order->customer ?? []));
		$emailVerified = $this->session->shop_order->email_verified ?? false;

		if (empty($customerData['email']) || empty($customerData['street'])) {
			$this->addMessage("Veuillez d'abord renseigner vos informations de livraison.", 'notice');
			$this->grav->redirect($absoluteCartUrl);
			exit;
		}

		// Filet de sécurité (profil ancien, session antérieure à la règle) :
		// livraison en France métropolitaine hors Corse uniquement.
		if (!AddressFields::isDeliverablePostalCode((string) ($customerData['postal-code'] ?? ''))) {
			$this->addMessage(AddressFields::UNSUPPORTED_ZONE_MESSAGE, 'error');
			$this->grav->redirect($absoluteCartUrl);
			exit;
		}

		if (!$emailVerified) {
			$this->addMessage("Veuillez valider votre adresse e-mail avant de choisir un mode de livraison.", 'notice');
			$this->grav->redirect($this->grav['base_url_absolute'] . $emailRoute . '?back_url=' . rawurlencode($absoluteDeliveryUrl));
			exit;
		}

		$cartItems = CartStorage::getCart();
		$shippingManager = new ShippingManager();

		$metrics = $shippingManager->getCartPhysicalMetrics($cartItems);
		$zone = ZoneResolver::resolve($customerData);
		$availableCarriers = $shippingManager->getAvailableCarriers($zone, $metrics);

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			
			$action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
			$nonce = filter_input(INPUT_POST, 'nonce', FILTER_DEFAULT);
			
			if ($action === 'select_shipping') {
				if (!$nonce || !Utils::verifyNonce($nonce, 'shop-delivery-action')) {
					$this->addMessage("Votre session de livraison a expiré, veuillez réessayer.", 'error');
					$this->grav->redirect($absoluteDeliveryUrl);
					exit;
				}

				$selectedCarrierKey = filter_input(INPUT_POST, 'shipping_method', FILTER_DEFAULT);

				if (!$selectedCarrierKey || !isset($availableCarriers[$selectedCarrierKey])) {
					$this->addMessage("Veuillez sélectionner un mode de livraison valide parmi les options proposées.", 'error');
					$this->grav->redirect($absoluteDeliveryUrl);
					exit;
				}

				$this->session->shop_order->shipping = $availableCarriers[$selectedCarrierKey];
				
				$this->grav->redirect($this->grav['base_url_absolute'] . $nextStepRoute);
				exit;
			}
		}

		$twig = $this->grav['twig'];
		$twig->twig_vars['customer'] = $customerData;
		$twig->twig_vars['cart_metrics'] = $metrics;
		$twig->twig_vars['carriers'] = $availableCarriers;
		$twig->twig_vars['current_shipping'] = $this->session->shop_order->shipping ?? [];
        return $this->renderStep('step2delivery');
    }

    public function step3Payment(): Response 
    {
		$config = $this->grav['config']->get('plugins.simple-shop');
		$cartRoute = $config['routes']['cart'] ?? '/cart';
		$deliveryRoute = $config['routes']['delivery'] ?? '/checkout/delivery';
		$emailRoute = $config['routes']['validation'] ?? '/checkout/email';

		$absoluteCartUrl = $this->grav['base_url_absolute'] . $cartRoute;

		// Revalidation systématique du panier par rapport au stock virtuel
		// courant, AVANT tout autre traitement de cette étape : en cas
		// d'ajustement, l'utilisateur est renvoyé au panier pour le relire.
		$cartId = CartStorage::getOrCreateCartId();
		$stockAdjustments = $this->revalidateCartStock($cartId);
		if (!empty($stockAdjustments)) {
			$this->grav->redirect($absoluteCartUrl);
			exit;
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
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
			$nonce = filter_input(INPUT_POST, 'nonce', FILTER_DEFAULT);

			if (!$nonce || !Utils::verifyNonce($nonce, 'shop-payment-step')) {
				$this->addMessage("Session expirée, veuillez réessayer.", 'error');
				return $this->redirect($this->grav['uri']->route());
			}

			// --- TEMPS 1 -> TEMPS 2 : Soumission Facturation & Choix Gateway ---
			if ($action === 'submit_billing') {
				$selectedGateway = filter_input(INPUT_POST, 'payment_method', FILTER_DEFAULT);
				
				if (empty($selectedGateway) || !isset($gateways[$selectedGateway])) {
					$this->addMessage("Veuillez sélectionner un mode de paiement valide.", 'error');
					return $this->redirect($this->grav['uri']->route());
				}

				// Sauvegarde de l'adresse de facturation
				$order->billing = [
					'name'          => filter_input(INPUT_POST, 'billing_name', FILTER_DEFAULT),
					'street'        => filter_input(INPUT_POST, 'billing_street', FILTER_DEFAULT),
					'postal-code'      => filter_input(INPUT_POST, 'billing_postal_code', FILTER_DEFAULT),
					'city'          => filter_input(INPUT_POST, 'billing_city', FILTER_DEFAULT),
					'address-complement' => filter_input(INPUT_POST, 'billing_address_complement', FILTER_DEFAULT),
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
				$result = $gateway->processPayment($_POST);

				if ($result['success']) {
					// Transformation du panier en commande persistée et récupération de son ID
					$orderNumber = CartStorage::toOrder();

					// Envoi des e-mails de commande (confirmation client +
					// notification vendeur). Volontairement non bloquant :
					// un échec d'envoi ne doit jamais empêcher l'affichage
					// de la confirmation au client (commande déjà persistée).
					$this->sendOrderEmails($orderNumber);

					// Nettoyage de l'état temporaire du paiement
					unset($order->payment_sub_step);
					unset($order->selected_gateway);

					// Préparation d'une redirection POST simulée (ou route directe) vers l'étape 4 (Confirmation)
					$confirmationRoute = $config['routes']['confirmation'] ?? '/checkout/confirmation';

					$confirmationUrl = $this->grav['base_url_absolute'] . $confirmationRoute;
					$safeOrderId = htmlspecialchars($orderNumber, ENT_QUOTES);

					$html  = "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Redirection...</title></head><body>";
					$html .= "<form id='redirect_form' method='POST' action='" . $confirmationUrl . "'>";
					$html .= "<input type='hidden' name='order_id' value='" . $safeOrderId . "'>";
					$html .= "</form><script>document.getElementById('redirect_form').submit();</script>";
					$html .= "</body></html>";

					return $this->html($html)->useDefaultLayout(false);
				} else {
					// Échec du paiement : retour au Temps 1 avec le message d'erreur
					$this->addMessage("Le paiement a échoué : " . ($result['error'] ?? 'Raison inconnue.'), 'error');
					$order->payment_sub_step = 'billing';
					return $this->redirect($this->grav['uri']->route());
				}
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
		$subtotal = 0;
		foreach ($cart as $item) {
			$subtotal += ($item['price'] ?? 0.0) * ($item['quantity'] ?? 0);
		}
		$order->billing_total = $subtotal + ($shippingData['price'] ?? 0);

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
		$checkoutInfoRoute = $config['routes']['cart'] ?? '/cart';
		$deliveryRoutePath = $config['routes']['delivery'] ?? '/checkout/delivery';

		$twig->twig_vars['routes'] = [
			'step1' => $this->grav['base_url_absolute'] . $checkoutInfoRoute,
			'step2' => $this->grav['base_url_absolute'] . $deliveryRoutePath
		];

        return $this->renderStep('step3payment');
    }

    public function step4ConfirmationOrder(): Response 
	{
		$orderReference = filter_input(INPUT_POST, 'order_id', FILTER_DEFAULT) 
			?? filter_input(INPUT_GET, 'order_id', FILTER_DEFAULT);

		if (!$orderReference) {
			$this->addMessage("Aucune référence de commande spécifiée.", 'error');
			$this->grav->redirect($this->grav['base_url_absolute'] . '/cart');
			exit;
		}

		$db = InventoryManager::getDatabase();
		$stmt = $db->prepare("SELECT * FROM \"order\" WHERE order_reference = :ref");
		$stmt->execute([':ref' => $orderReference]);
		$orderData = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!$orderData) {
			$this->addMessage("Commande introuvable.", 'error');
			$this->grav->redirect($this->grav['base_url_absolute'] . '/cart');
			exit;
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
}
