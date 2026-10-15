<?php
// Types de produits : découverte dans les blueprints (bloc `shop:`), cache, type générique de repli, erreurs de déclaration,
// règles du type « book » du thème bookstore (prévente, vente ailleurs, expédition, présentation).
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/product-types.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Product\Catalog;
use Grav\Plugin\SimpleShop\Product\ProductTypes;
use Grav\Plugin\SimpleShop\Pricing\Price;
use SimpleShopTest\FakePage;

$future = date('Y-m-d', strtotime('+30 days'));
$past = date('Y-m-d', strtotime('-30 days'));

$pages = [
    // Livre paru : prix public (ancienne saisie HT à 4 décimales → TTC d'origine 12,95 €)
    new FakePage('/livres/paru', ['title' => 'Paru', 'product_price' => '12.2749', 'product_presale_price' => '9', 'publication_date' => $past, 'product_quantity' => 5, 'weight' => 250, 'thickness' => 1.5], 'book'),
    // Livre à paraître : prix de prévente
    new FakePage('/livres/futur', ['title' => 'Futur', 'product_price' => '20', 'product_presale_price' => '15', 'publication_date' => $future, 'product_quantity' => -1], 'book'),
    // À paraître sans prix de prévente : pas encore vendable
    new FakePage('/livres/futur-sans-prevente', ['title' => 'Futur 2', 'product_price' => '20', 'publication_date' => $future], 'book'),
    // Vendu ailleurs
    new FakePage('/livres/ailleurs', ['title' => 'Ailleurs', 'product_price' => '20', 'sales_method' => 'sell_elsewhere'], 'book'),
    // Saisie TTC sur la fiche
    new FakePage('/livres/ttc', ['title' => 'TTC', 'product_price' => '10.00', 'price_entered_as' => 'ttc'], 'book'),
    // Gabarit inconnu mais avec un prix : produit générique (type « default »), épaisseur en mm
    new FakePage('/divers/affiche', ['title' => 'Affiche', 'product_price' => '10', 'product_quantity' => 2, 'weight' => 80, 'thickness' => 3], 'poster'),
    // Page sans prix : pas un produit
    new FakePage('/a-propos', ['title' => 'À propos'], 'default'),
];
$grav = \SimpleShopTest\boot([], $pages, null);

// 1. Découverte : le type « book » vient du blueprint du thème, avec sa taxe
$types = ProductTypes::all();
assert(isset($types['book']), 'type book découvert dans themes/bookstore/blueprints/book.yaml');
assert($types['book']->taxCode === 'reduit', 'classe de taxe du type book');
assert(ProductTypes::errors() === [], 'aucune erreur de déclaration : ' . implode(' | ', ProductTypes::errors()));

// 2. Prix du type book
$p = Catalog::price('/livres/paru');
assert($p instanceof Price && $p->unitTtc->amount === 1295, 'ancien HT 12.2749 → 12,95 € TTC, obtenu ' . ($p ? $p->unitTtc->amount : 'null'));
$p = Catalog::price('/livres/futur');
assert($p && $p->unitHt->amount === 1500 && $p->unitTtc->amount === 1583, 'prévente 15 € HT → 15,83 € TTC');
assert(Catalog::price('/livres/futur-sans-prevente') === null, 'à paraître sans prix de prévente : non vendable');
assert(Catalog::price('/livres/ailleurs') === null, 'vendu ailleurs : non vendable ici');
$p = Catalog::price('/livres/ttc');
assert($p && $p->unitTtc->amount === 1000 && $p->enteredAs === Price::TTC, 'saisie TTC sur la fiche conservée');
assert(json_decode(json_encode($p), true)['tax_code'] === 'reduit' && json_decode(json_encode($p), true)['currency'] === 'EUR', 'contrat : taxe et devise dans la réponse');

// 3. Expédition et présentation du type book (épaisseur en cm → mm ; 100 g par défaut)
[$page, $type] = Catalog::resolve('/livres/paru');
assert($type->shipping($page, []) === ['weight_g' => 250, 'thickness_mm' => 15], 'expédition book');
[$page, $type] = Catalog::resolve('/livres/futur');
assert($type->shipping($page, [])['weight_g'] === 100, 'poids par défaut d\'un livre');
assert($type->stock($page, []) === null, 'stock -1 = illimité');
assert($type->presentation($page, [])['label'] === 'Futur', 'présentation book');

// 4. Type générique de repli
[$page, $type] = Catalog::resolve('/divers/affiche');
assert($type->name === ProductTypes::FALLBACK, 'gabarit non déclaré + product_price → type générique');
assert($type->shipping($page, []) === ['weight_g' => 80, 'thickness_mm' => 3], 'expédition générique (mm)');
assert($type->stock($page, []) === 2, 'stock générique');
assert(Catalog::price('/divers/affiche')->unitTtc->amount === 1200, 'générique : 10 € HT → 12,00 € TTC (classe par défaut, taux normal)');
assert(Catalog::resolve('/a-propos') === null, 'page sans prix : pas un produit');
try {
    $type->options($page, ['taille' => 'A2']);
    assert(false, 'le type générique refuse les options');
} catch (\InvalidArgumentException $e) {
}

// 5. Erreurs de déclaration et cache
$dir = sys_get_temp_dir() . '/simple-shop-types-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/cassé.yaml', "shop:\n  price_calculation: 'Inexistant::prix'\n");
file_put_contents($dir . '/demi.yaml', "shop:\n  price_calculation: 'Grav\\Plugin\\SimpleShop\\Product\\GenericProduct::price'\n  shipping: 'Inexistant::envoi'\n  tax: TVA_20\n");
file_put_contents($dir . '/page.yaml', "title: Page\n");   // sans bloc shop : pas un type
$calls = 0;
$store = [];
$cache = function (string $key, callable $compute) use (&$calls, &$store) {
    $calls++;
    return $store[$key] ??= $compute();
};
$found = ProductTypes::discover([$dir], $cache);
assert(array_keys($found) === ['demi'], 'type sans price_calculation valide écarté, blueprint sans shop ignoré');
assert(count(ProductTypes::errors()) === 2, 'deux erreurs signalées : ' . implode(' | ', ProductTypes::errors()));
assert($found['demi']->taxCode === 'TVA_20', 'taxe propre au type');
$keys1 = array_keys($store);
ProductTypes::discover([$dir], $cache);
assert(array_keys($store) === $keys1, 'même fichiers → même clé de cache');
touch($dir . '/demi.yaml', time() + 10);
clearstatcache();
ProductTypes::discover([$dir], $cache);
assert(count($store) === 2, 'fichier modifié → nouvelle clé de cache');
exec('rm -rf ' . escapeshellarg($dir));

echo "OK\n";
