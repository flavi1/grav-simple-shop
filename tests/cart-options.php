<?php
// Lignes de panier « produit + options » (étape 6) : une ligne par jeu d'options, options validées par le type de produit,
// prix selon les options, réservation de stock par produit, options gardées sur la commande.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/cart-options.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\Mvc\Request;
use Grav\Plugin\SimpleShop\Controllers\CartController;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Product\GenericProduct;
use Grav\Plugin\SimpleShop\Product\ProductTypes;
use SimpleShopTest\FakePage;

/** Type de test : un t-shirt en tailles S, M, L (L : +2 €), avec un texte brodé facultatif. */
final class TshirtType
{
    public static function options($page, array $raw): array
    {
        $size = (string) ($raw['taille'] ?? '');
        if (!in_array($size, ['S', 'M', 'L'], true)) {
            throw new InvalidArgumentException('Choisissez une taille (S, M ou L).');
        }
        $out = ['taille' => $size];
        $text = trim((string) ($raw['broderie'] ?? ''));
        if ($text !== '') {
            $out['broderie'] = mb_substr($text, 0, 20);
        }

        return $out;
    }

    public static function price($page, array $options, $ctx)
    {
        $h = (array) $page->header();
        if (($options['taille'] ?? '') === 'L') {
            $h['product_price'] = (string) ((float) $h['product_price'] + 2);
        }

        return GenericProduct::priceFromField($h, 'product_price', $ctx);
    }
}

$shirt = new FakePage('/boutique/t-shirt', ['title' => 'T-shirt', 'product_price' => '10', 'product_quantity' => '5'], 'tshirt');
$grav = \SimpleShopTest\boot([], [$shirt], null);
ProductTypes::set(['tshirt' => ['price_calculation' => 'TshirtType::price', 'options' => 'TshirtType::options']]);
$session = $grav['session'];

$call = function (array $post) use ($grav) {
    $grav['uri']->current = '/cart';
    $controller = new CartController($grav, new Request('POST', [], $post + ['nonce' => 'nonce-shop-cart-action'], ['REQUEST_METHOD' => 'POST']));

    return $controller->step1Cart();
};
$add = fn (array $options) => $call(['action' => 'add_item', 'product_route' => '/boutique/t-shirt', 'options' => $options]);

// 1. Options refusées par le type : rien n'est ajouté
$add(['taille' => 'XXL']);
assert(CartStorage::getCart() === [], 'taille inconnue refusée');
$add([]);
assert(CartStorage::getCart() === [], 'option obligatoire absente refusée');

// 2. Une ligne par jeu d'options ; le même jeu (clés dans un autre ordre) retombe sur la même ligne
$add(['taille' => 'M']);
$add(['taille' => 'L', 'broderie' => 'Marie']);
$add(['broderie' => 'Marie', 'taille' => 'L']);
$cart = CartStorage::getCart();
assert(count($cart) === 2, 'deux lignes : ' . count($cart));
$m = $cart[CartStorage::lineId('/boutique/t-shirt', ['taille' => 'M'])];
$l = $cart[CartStorage::lineId('/boutique/t-shirt', ['taille' => 'L', 'broderie' => 'Marie'])];
assert($m['quantity'] === 1 && $m['options'] === ['taille' => 'M'] && $m['route'] === '/boutique/t-shirt');
assert($l['quantity'] === 2 && $l['options'] === ['broderie' => 'Marie', 'taille' => 'L'], json_encode($l['options']));
assert($m['unit_ttc_cents'] === 1200 && $l['unit_ttc_cents'] === 1440, 'prix (classe par défaut : taux normal 20 %) selon les options : ' . $m['unit_ttc_cents'] . ' / ' . $l['unit_ttc_cents']);

// 3. Stock réservé par produit (toutes options confondues) : 5 en stock, 3 au panier
assert(InventoryManager::getVirtualStock('/boutique/t-shirt') === 2, 'stock virtuel = 5 − 3');
$call(['action' => 'update_quantity', 'line_id' => $m['line_id'], 'quantity' => '9']);
assert(CartStorage::getCart()[$m['line_id']]['quantity'] === 3, 'quantité plafonnée au stock (1 + 2 disponibles)');

// 4. Retrait d'une ligne par son identifiant ; un identifiant d'un autre panier est ignoré
$call(['action' => 'remove_item', 'line_id' => 'inconnu']);
assert(count(CartStorage::getCart()) === 2);
$call(['action' => 'remove_item', 'line_id' => $m['line_id']]);
assert(array_keys(CartStorage::getCart()) === [$l['line_id']], 'ligne M retirée');

// 5. Commande : options et identifiant de ligne enregistrés, déstockage par produit
$created = null;
$ref = CartStorage::toOrder(null, $created, ['cart_id' => $session->shop_order->cart_id, 'customer' => [], 'shipping' => [], 'billing' => []]);
$item = InventoryManager::getDatabase()->query("SELECT * FROM order_item WHERE order_reference = '{$ref}'")->fetch(PDO::FETCH_ASSOC);
assert($item['line_id'] === $l['line_id'] && json_decode($item['options'], true) === ['broderie' => 'Marie', 'taille' => 'L'], json_encode($item));
assert((int) $item['unit_ttc_cents'] === 1440 && (int) $item['quantity'] === 2);
assert(str_contains(file_get_contents($shirt->file), "product_quantity: '3'") || str_contains(file_get_contents($shirt->file), 'product_quantity: 3'), 'stock 5 → 3');

// 6. Reprise des anciennes lignes cart_item (sans options) dans cart_line
$db = InventoryManager::getDatabase();
$db->exec("INSERT INTO cart (cart_id, expires_at) VALUES ('ancien', datetime('now', '+1 hour'))");
$db->exec("INSERT INTO cart_item (cart_id, product_route, quantity) VALUES ('ancien', '/boutique/t-shirt', 1)");
$db = null;
@unlink(dirname($grav['locator']->findResource('user://data/simple-shop/shop.db', true, true)) . '/.shop-schema-applied');
$db = InventoryManager::getDatabase();
assert((int) $db->query("SELECT COUNT(*) FROM cart_item")->fetchColumn() === 0, 'cart_item vidée');
assert($db->query("SELECT line_id FROM cart_line WHERE cart_id = 'ancien'")->fetchColumn() === CartStorage::lineId('/boutique/t-shirt', []), 'ligne reprise');

echo "OK\n";
