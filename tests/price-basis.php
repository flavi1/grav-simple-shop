<?php
// Passage du site de la saisie HT à la saisie TTC : conversion unique des fiches sans base explicite, prix publics inchangés.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/price-basis.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Product\Catalog;
use Grav\Plugin\SimpleShop\Product\PriceBasisMigration;
use SimpleShopTest\FakePage;

$future = date('Y-m-d', strtotime('+30 days'));
$pages = [
    'paru'     => new FakePage('/livres/paru', ['title' => 'Paru', 'product_price' => '12.2749', 'product_presale_price' => '9', 'product_quantity' => 5], 'book'),
    'futur'    => new FakePage('/livres/futur', ['title' => 'Futur', 'product_price' => '20', 'product_presale_price' => '14.2180', 'publication_date' => $future, 'product_quantity' => 5], 'book'),
    'explicit' => new FakePage('/livres/explicite', ['title' => 'Explicite', 'product_price' => '10', 'price_entered_as' => 'ht', 'product_quantity' => 5], 'book'),
    'generic'  => new FakePage('/divers/affiche', ['title' => 'Affiche', 'product_price' => '10', 'product_quantity' => 2], 'poster'),
    'page'     => new FakePage('/a-propos', ['title' => 'À propos'], 'default'),
];

// Avant : site en HT
$grav = \SimpleShopTest\boot([], array_values($pages), null);
$before = array_map(fn ($r) => Catalog::price($r)?->unitTtc->amount, ['/livres/paru', '/livres/futur', '/livres/explicite', '/divers/affiche']);
assert($before === [1295, 1500, 1055, 1055], json_encode($before));
$dataDir = $grav['locator']->findResource('user://data/simple-shop', true, true);
$config = (array) $grav['config']->get('plugins.simple-shop');
assert(PriceBasisMigration::run($grav['pages']->all(), $dataDir, $config) === null, 'site toujours en HT : rien à faire');

// Le site passe en TTC
$grav['config']->set('plugins.simple-shop.taxes.prices_entered', 'ttc');
$config = (array) $grav['config']->get('plugins.simple-shop');
$logs = [];
$r = PriceBasisMigration::run($grav['pages']->all(), $dataDir, $config, function ($l, $m) use (&$logs) { $logs[] = $m; });
assert($r === ['from' => 'ht', 'to' => 'ttc', 'pages' => 3, 'fields' => 5], json_encode($r));
assert(trim(file_get_contents($dataDir . '/' . PriceBasisMigration::MARKER)) === 'ttc');

// Fichiers réécrits (seulement les lignes de prix), fiche explicite intacte
$paru = file_get_contents($pages['paru']->file);
assert(strpos($paru, "product_price: '12.95'") !== false && strpos($paru, "product_presale_price: '9.50'") !== false, $paru);
assert(strpos($paru, "title: Paru") !== false && strpos($paru, 'product_quantity: 5') !== false, 'autres lignes intactes');
assert(strpos(file_get_contents($pages['futur']->file), "product_presale_price: '15.00'") !== false, '14.2180 HT → 15.00 TTC');
assert(strpos(file_get_contents($pages['explicit']->file), "product_price: '10'") !== false, 'base explicite : non convertie');
assert(strpos(file_get_contents($pages['generic']->file), "product_price: '10.55'") !== false, 'produit générique converti');

// Prix publics inchangés, lus désormais en TTC (en mémoire comme sur disque)
$after = array_map(fn ($r) => Catalog::price($r)?->unitTtc->amount, ['/livres/paru', '/livres/futur', '/livres/explicite', '/divers/affiche']);
assert($after === $before, 'prix TTC inchangés : ' . json_encode($after));
assert(Catalog::price('/livres/paru')->enteredAs === 'ttc');

// Rejouer : rien à faire
assert(PriceBasisMigration::run($grav['pages']->all(), $dataDir, $config) === null, 'conversion unique');

// Retour en HT : HT au centime
$grav['config']->set('plugins.simple-shop.taxes.prices_entered', 'ht');
$r = PriceBasisMigration::run($grav['pages']->all(), $dataDir, (array) $grav['config']->get('plugins.simple-shop'));
assert($r['to'] === 'ht' && strpos(file_get_contents($pages['paru']->file), "product_price: '12.27'") !== false, 'TTC 12.95 → HT 12.27');

echo "OK\n";
