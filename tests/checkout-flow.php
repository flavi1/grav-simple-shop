<?php
// Test de bout en bout du tunnel d'achat, sans Grav (doublures : tests/support/bootstrap.php).
// Panier → coordonnées → (e-mail vérifié) → livraison → facturation → paiement de test → commande → confirmation.
// Vérifie aussi que chaque étape retourne une Response (jamais d'exit) et les cas de refus usuels.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/checkout-flow.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\Mvc\Request;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Controllers\CartController;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use SimpleShopTest\FakePage;

$carriers = <<<'YAML'
constraints:
  - name: "Loi Darcos"
    product_types: [book]
    rules:
      - { max_amount: 34.99, type: minimum, value: 3.00, comment: "Livres : au moins 3 €" }
      - { min_amount: 35, type: minimum, value: 0.01 }
carriers:
  colis:
    name: "Colis"
    enabled: true
    rules:
      - { zone: FR-METRO, min_weight: 0, max_weight: 1000, price: 7.57 }
  forfait:
    name: "Livraison forfaitaire"
    enabled: true
    pricing: flat
    flat_price: 0
YAML;

$book = new FakePage('/livres/un-livre', [
    'title' => 'Un livre', 'product_price' => '12.2749', 'product_quantity' => '10', 'sales_method' => 'sell_here',
    'weight' => '300', 'thickness' => '2.3',
], 'book');
$grav = \SimpleShopTest\boot([], [$book], $carriers);
$session = $grav['session'];

/** Exécute une étape du contrôleur comme une requête HTTP ; retourne la Response. */
$call = function (string $method, string $route, array $post = [], array $get = []) use ($grav): Response {
    $grav['uri']->current = $route;
    $request = new Request($post === [] ? 'GET' : 'POST', $get, $post, ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST']);
    $controller = new CartController($grav, $request);
    $response = $controller->$method();
    assert($response instanceof Response, "{$method} doit retourner une Response");
    return $response;
};
$redirect = fn (Response $r) => $r->isRedirect() ? $r->getRedirectUrl() : null;

// --- 1. Panier : ajout (nonce invalide refusé, puis valide)
$r = $call('step1Cart', '/cart', ['action' => 'add_item', 'product_route' => '/livres/un-livre', 'nonce' => 'faux']);
assert($redirect($r) === 'https://shop.test/cart');
assert(count(InventoryManager::getDatabase()->query('SELECT * FROM cart_line')->fetchAll()) === 0, 'nonce invalide : rien ajouté');
$r = $call('step1Cart', '/cart', ['action' => 'add_item', 'product_route' => '/livres/un-livre', 'nonce' => 'nonce-shop-cart-action']);
assert($redirect($r) === 'https://shop.test/cart');
$r = $call('step1Cart', '/cart', ['action' => 'update_quantity', 'product_route' => '/livres/un-livre', 'quantity' => '2', 'nonce' => 'nonce-shop-cart-action']);
$cart = \Grav\Plugin\SimpleShop\Inventory\CartStorage::getCart();
$line = $cart[\Grav\Plugin\SimpleShop\Inventory\CartStorage::lineId('/livres/un-livre', [])];
assert($line['quantity'] === 2 && abs($line['price'] - 12.95) < 0.001 && $line['route'] === '/livres/un-livre', 'prix TTC 12,95 € (12,2749 HT × 1,055)');
$r = $call('step1Cart', '/cart');
assert(!$r->isRedirect() && $r->getTemplate() === 'simple-shop/step1cart');

// --- 2. Coordonnées : CGV obligatoires, puis passage à la vérification de l'e-mail
$customer = ['firstname' => 'Marie', 'lastname' => 'Curie', 'email' => 'marie@example.org', 'tel' => '0601020304',
    'street' => '1 rue de la Paix', 'postal-code' => '75002', 'city' => 'Paris'];
$r = $call('step1Cart', '/cart', ['action' => 'process_checkout', 'nonce' => 'nonce-shop-checkout-action'] + $customer);
assert($redirect($r) === 'https://shop.test/cart', 'CGV non cochées : refus');
$r = $call('step1Cart', '/cart', ['action' => 'process_checkout', 'nonce' => 'nonce-shop-checkout-action', 'accept-cgv' => '1'] + $customer);
assert(strpos((string) $redirect($r), 'https://shop.test/checkout/email') === 0, (string) $redirect($r));

// Sans e-mail vérifié, la livraison renvoie vers la vérification.
$r = $call('step2Delivery', '/checkout/delivery');
assert(strpos((string) $redirect($r), 'https://shop.test/checkout/email') === 0);
$session->shop_order->email_verified = true;   // code OTP validé (hors périmètre de ce test)

// --- 3. Livraison : modes proposés et prix (forfait 0 € relevé à 3,00 € par la loi Darcos ; 25,90 € de livres)
$r = $call('step2Delivery', '/checkout/delivery');
$carriersShown = $grav['twig']->twig_vars['carriers'];
assert(!$r->isRedirect() && isset($carriersShown['forfait'], $carriersShown['colis']));
assert($carriersShown['forfait']['price'] === 3.0, 'forfait 0 € → 3,00 € (Darcos)');
assert($carriersShown['colis']['price'] === 7.99, 'colis 7,57 € HT → 7,99 € TTC');
assert(array_keys($carriersShown) === ['forfait', 'colis'], 'du moins cher au plus cher');
$r = $call('step2Delivery', '/checkout/delivery', ['action' => 'select_shipping', 'shipping_method' => 'inconnu', 'nonce' => 'nonce-shop-delivery-action']);
assert($redirect($r) === 'https://shop.test/checkout/delivery', 'mode inconnu refusé');
$r = $call('step2Delivery', '/checkout/delivery', ['action' => 'select_shipping', 'shipping_method' => 'colis', 'nonce' => 'nonce-shop-delivery-action']);
assert($redirect($r) === 'https://shop.test/checkout/payment');
// Retour sur l'étape livraison : le choix du client (plus cher) reste coché pour ce panier ; panier modifié → le moins cher.
$call('step2Delivery', '/checkout/delivery');
assert($grav['twig']->twig_vars['preselected_shipping'] === 'colis', 'dernier choix conservé pour le même panier');
$call('step1Cart', '/cart', ['action' => 'update_quantity', 'product_route' => '/livres/un-livre', 'quantity' => '3', 'nonce' => 'nonce-shop-cart-action']);
$call('step2Delivery', '/checkout/delivery');
assert($grav['twig']->twig_vars['preselected_shipping'] === array_key_first($grav['twig']->twig_vars['carriers']), 'panier modifié : le moins cher');
$call('step1Cart', '/cart', ['action' => 'update_quantity', 'product_route' => '/livres/un-livre', 'quantity' => '2', 'nonce' => 'nonce-shop-cart-action']);
$r = $call('step2Delivery', '/checkout/delivery', ['action' => 'select_shipping', 'shipping_method' => 'colis', 'nonce' => 'nonce-shop-delivery-action']);
assert($redirect($r) === 'https://shop.test/checkout/payment');

// --- 4. Facturation et paiement de test (échec, puis succès)
$r = $call('step3Payment', '/checkout/payment');
assert(!$r->isRedirect() && abs($session->shop_order->billing_total - (25.90 + 7.99)) < 0.001, 'total = 2 × 12,95 + 7,99');
assert($session->shop_order->billing_total_cents === 3389 && $session->shop_order->billing_currency === 'EUR', 'montant à encaisser en centimes');
assert($session->shop_order->shipping['price_cents'] === 799, 'livraison en centimes');
$billing = ['billing_name' => 'Marie Curie', 'billing_street' => '1 rue de la Paix', 'billing_postal_code' => '75002', 'billing_city' => 'Paris'];
$r = $call('step3Payment', '/checkout/payment', ['action' => 'submit_billing', 'payment_method' => 'test_gateway', 'nonce' => 'nonce-shop-payment-step'] + $billing);
assert($redirect($r) === '/checkout/payment' && $session->shop_order->payment_sub_step === 'gateway_form');
$r = $call('step3Payment', '/checkout/payment', ['action' => 'execute_payment', 'test_payment_status' => 'failure', 'nonce' => 'nonce-shop-payment-step']);
assert($session->shop_order->payment_sub_step === 'billing', 'échec : retour à la facturation');
$call('step3Payment', '/checkout/payment', ['action' => 'submit_billing', 'payment_method' => 'test_gateway', 'nonce' => 'nonce-shop-payment-step'] + $billing);
$r = $call('step3Payment', '/checkout/payment', ['action' => 'execute_payment', 'test_payment_status' => 'success', 'nonce' => 'nonce-shop-payment-step']);
assert($redirect($r) === 'https://shop.test/checkout/confirmation', (string) $redirect($r));

// --- 5. Commande enregistrée, panier vidé, confirmation
$db = InventoryManager::getDatabase();
$order = $db->query('SELECT * FROM "order"')->fetch(PDO::FETCH_ASSOC);
assert($order && abs((float) $order['total_amount'] - 33.89) < 0.001, 'montant de la commande');
$items = $db->query('SELECT * FROM order_item')->fetchAll(PDO::FETCH_ASSOC);
assert(count($items) === 1 && (int) $items[0]['quantity'] === 2 && abs((float) $items[0]['price'] - 12.95) < 0.001);
// Instantané par ligne : prix HT/TTC en centimes, taxe et type figés (la facture ne relit plus la configuration).
assert((int) $order['total_cents'] === 3389 && $order['currency'] === 'EUR', 'total en centimes');
assert((int) $order['shipping_ttc_cents'] === 799 && (int) $order['shipping_ht_cents'] === 757 && (int) $order['shipping_tax_bp'] === 550, 'livraison : 7,99 TTC = 7,57 HT à 5,5 %');
assert((int) $items[0]['unit_ttc_cents'] === 1295 && (int) $items[0]['unit_ht_cents'] === 1227 && $items[0]['tax_code'] === 'taux-reduit'
    && (int) $items[0]['tax_bp'] === 550 && $items[0]['product_type'] === 'book', 'ligne : 12,95 TTC, 12,27 HT, Taux réduit, book');
assert(json_decode($order['shipping_data'], true)['key'] === 'colis');
assert(strpos(file_get_contents($book->file), "product_quantity: '8'") !== false || strpos(file_get_contents($book->file), 'product_quantity: 8') !== false, 'stock 10 → 8 : ' . file_get_contents($book->file));
$invoice = $db->query('SELECT * FROM invoice')->fetch(PDO::FETCH_ASSOC);
assert($invoice !== false, 'facture créée avec la commande');
$r = $call('step4ConfirmationOrder', '/checkout/confirmation');
assert(!$r->isRedirect() && $grav['twig']->twig_vars['order_reference'] === $order['order_reference']);
$r = $call('step4ConfirmationOrder', '/checkout/confirmation', [], ['order_id' => 'CMD-AUTRE']);
assert($redirect($r) === 'https://shop.test/cart', 'une autre référence ne s’affiche pas');

// --- 6. Paramètres forgés (tableaux au lieu de chaînes) : pas d'erreur PHP, refus propre
$r = $call('step1Cart', '/cart', ['action' => ['x'], 'nonce' => ['y']]);
assert($r instanceof Response);

if (getenv("DUMP_LOG")) { foreach ($grav["log"]->lines as [$l, $m]) { echo "$l: $m\n"; } }
echo "OK\n";
