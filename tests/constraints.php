<?php
// Tests en ligne de commande des contraintes globales (ShippingConstraints) : conditions, ordre, types, exclusions,
// conversion de l'ancien réglage `delivery.book_law`.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/constraints.php
if (getenv('YAML')) {
    require getenv('YAML');
}
spl_autoload_register(function ($c) {
    $p = 'Grav\\Plugin\\SimpleShop\\';
    if (strpos($c, $p) === 0) {
        $f = __DIR__ . '/../classes/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

use Grav\Plugin\SimpleShop\Delivery\{CarrierCatalog, CarrierConfig, ShippingConstraints as SC, ShippingManager};

// Exemple du propriétaire : loi Darcos (livres) + livraison offerte à partir de 50 € (tous articles).
$list = SC::normalize([
    ['name' => 'Loi Darcos', 'product_types' => ['book'], 'except_modes' => ['retrait'], 'rules' => [
        ['min_amount' => 0, 'max_amount' => 34.99, 'type' => 'minimum', 'value' => 3.00, 'comment' => 'Livres < 35 €'],
        ['min_amount' => 34.99, 'max_amount' => 49.99, 'type' => 'minimum', 'value' => 0.01],
    ]],
    ['name' => 'Offerte dès 50 €', 'rules' => [
        ['min_amount' => 50, 'type' => 'fixed', 'value' => 0.01, 'comment' => 'livraison offerte à partir de 50 €.'],
    ]],
]);
$m = fn (float $books, float $other = 0, int $w = 500) => ['weight' => $w, 'subtotal' => $books + $other,
    'subtotals' => array_filter(['book' => $books, 'goodies' => $other], static fn ($v) => $v > 0)];

[$p, $a] = SC::apply(0.0, 'colissimo.domicile', $m(12.95), $list);
assert($p === 3.0 && count($a) === 1 && $a[0]['name'] === 'Loi Darcos' && $a[0]['comment'] === 'Livres < 35 €');
[$p] = SC::apply(5.0, 'colissimo.domicile', $m(12.95), $list);
assert($p === 5.0, 'minimum : un prix supérieur est conservé');
[$p] = SC::apply(0.0, 'colissimo.domicile', $m(34.99), $list);
assert($p === 3.0, 'bornes incluses, première règle qui correspond');
[$p] = SC::apply(0.0, 'colissimo.domicile', $m(40), $list);
assert($p === 0.01);
[$p, $a] = SC::apply(8.44, 'colissimo.domicile', $m(60), $list);
assert($p === 0.01 && count($a) === 1 && $a[0]['type'] === 'fixed', 'prix imposé dès 50 €');
// Le montant comparé est celui des articles concernés : 20 € de livres + 40 € d'autres articles.
[$p, $a] = SC::apply(0.0, 'colissimo.domicile', $m(20, 40), $list);
assert($p === 0.01 && count($a) === 2, 'Darcos (20 € de livres → 3 €), puis offerte (60 € au total → 0,01 €), dans cet ordre');
// Panier sans livre : la contrainte « livres » ne s'applique pas.
[$p, $a] = SC::apply(0.0, 'colissimo.domicile', $m(0, 10), $list);
assert($p === 0.0 && $a === []);
// Mode exclu (retrait sur place).
[$p] = SC::apply(0.0, 'retrait', $m(12.95), $list);
assert($p === 0.0);
// Maximum et conditions de poids.
$cap = SC::normalize([['name' => 'Plafond', 'rules' => [['min_weight' => 1000, 'type' => 'maximum', 'value' => 9.9]]]]);
assert(SC::apply(15.0, 'x', $m(10, 0, 1500), $cap)[0] === 9.9 && SC::apply(15.0, 'x', $m(10, 0, 500), $cap)[0] === 15.0);

// --- Validation de la saisie
[, $e] = SC::sanitize([['name' => '', 'rules' => []]]);
assert(count($e) >= 2, 'nom et règle obligatoires');
[, $e] = SC::sanitize([['name' => 'X', 'rules' => [['type' => 'autre', 'value' => 1]]]]);
assert($e !== []);
[, $e] = SC::sanitize([['name' => 'X', 'rules' => [['type' => 'minimum', 'value' => '-1']]]]);
assert($e !== []);
[, $e] = SC::sanitize([['name' => 'X', 'rules' => [['type' => 'minimum', 'value' => 1, 'min_amount' => 50, 'max_amount' => 10]]]]);
assert($e !== []);
[$c, $e] = SC::sanitize([['name' => 'X', 'product_types' => ['book'], 'rules' => [['type' => 'minimum', 'value' => '3,5', 'max_amount' => '34,99', 'min_weight' => '']]]]);
assert($e === [] && $c[0]['rules'][0]['value'] === 3.5 && $c[0]['rules'][0]['max_amount'] === 34.99 && $c[0]['rules'][0]['min_weight'] === null);
assert(SC::toStorage($c) === [['name' => 'X', 'product_types' => ['book'], 'rules' => [['max_amount' => 34.99, 'type' => 'minimum', 'value' => 3.5]]]]);

// --- Conversion de l'ancien réglage
assert(SC::fromLegacy([], []) === [], 'sans book_law : aucune contrainte (rien de propre aux livres par défaut)');
$legacy = SC::fromLegacy(['delivery' => ['book_law' => ['enabled' => true]]], ['retrait_atelier' => ['legal_floor' => false], 'lettre' => []]);
assert(count($legacy) === 1 && $legacy[0]['product_types'] === ['book'] && $legacy[0]['except_modes'] === ['retrait_atelier']);
assert(SC::apply(0.0, 'lettre', $m(34.99), $legacy)[0] === 3.0 && SC::apply(0.0, 'lettre', $m(35), $legacy)[0] === 0.01);
assert(SC::fromLegacy(['delivery' => ['book_law' => ['enabled' => false]]], []) === []);

// --- Intégration au calcul d'un mode (forfait 0 € HT)
$config = ['taxes' => ['default' => 'TVA_5_5'], 'delivery' => ['prices_entered' => 'ht']];
$mode = CarrierCatalog::build([], ['f' => ['name' => 'F', 'enabled' => true, 'pricing' => 'flat', 'flat_price' => 0]])['f'];
$q = ShippingManager::quoteMode($mode, 'FR-METRO', $m(12.95), $config, false, $list);
assert($q['price'] === 3.0 && $q['price_before_constraints'] === 0.0 && $q['constraints'][0]['after'] === 3.0);

// --- Fichier YAML
if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
    assert(CarrierConfig::validate(CarrierConfig::dump([], SC::toStorage($list))) === []);
    assert(CarrierConfig::validate("constraints: { a: 1 }\ncarriers: {}\n") !== []);
    assert(CarrierConfig::validate("constraints:\n  - name: X\n    rules: [{ type: minimum }]\ncarriers: {}\n") !== []);
}

echo "OK\n";
