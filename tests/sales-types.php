<?php
// Ventes actives par type de produit (configuration `sales.types`) : un type décoché n'a ni prix, ni données d'achat, ni ajout
// au panier ; réglage jamais enregistré = tous les types en vente ; variable Twig `shop_sales`.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/sales-types.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Product\Catalog;
use Grav\Plugin\SimpleShop\Product\ProductTypes;
use SimpleShopTest\FakePage;

$pages = [
    new FakePage('/affiches/a', ['title' => 'Affiche', 'product_price' => '10', 'product_quantity' => 5], 'poster'),
    new FakePage('/tasses/t', ['title' => 'Tasse', 'product_price' => '8', 'product_quantity' => 5], 'mug'),
];
$types = [
    'poster' => ['price_calculation' => 'Grav\Plugin\SimpleShop\Product\GenericProduct::price', '_title' => 'Affiche'],
    'mug'    => ['price_calculation' => 'Grav\Plugin\SimpleShop\Product\GenericProduct::price'],
];

// Réglage jamais enregistré : tout est en vente (comportement antérieur)
$grav = \SimpleShopTest\boot([], $pages, null);
ProductTypes::set($types);
assert(ProductTypes::salesMap() === ['poster' => true, 'mug' => true]);
assert(Catalog::price('/affiches/a') !== null && Catalog::price('/tasses/t') !== null);
assert(ProductTypes::salesOptions() === ['poster' => 'Affiche (poster)', 'mug' => 'mug (mug)'], json_encode(ProductTypes::salesOptions()));
assert(ProductTypes::salesDefault() === ['poster' => true, 'mug' => true], 'cases cochées par défaut');

// Cases à cocher enregistrées par Admin2 (use: keys → type => bool) : seules les affiches en vente
$grav['config']->set('plugins.simple-shop.sales.types', ['poster' => true, 'mug' => false]);
assert(ProductTypes::salesMap() === ['poster' => true, 'mug' => false]);
assert(Catalog::price('/affiches/a') !== null, 'type en vente : prix');
assert(Catalog::price('/tasses/t') === null, 'type hors vente : pas de prix');
assert(InventoryManager::getProductData('/tasses/t') === false && InventoryManager::getProductData('/affiches/a') !== false, 'pas de données d\'achat hors vente');

// Autres formats tolérés : liste de noms, tout décoché
$grav['config']->set('plugins.simple-shop.sales.types', ['mug']);
assert(ProductTypes::salesMap() === ['poster' => false, 'mug' => true]);
$grav['config']->set('plugins.simple-shop.sales.types', []);
assert(ProductTypes::salesMap() === ['poster' => false, 'mug' => false], 'tout décoché : rien en vente');
assert(ProductTypes::salesEnabled('poster', ['sales' => ['types' => ['poster' => '0']]]) === false);

echo "OK\n";
