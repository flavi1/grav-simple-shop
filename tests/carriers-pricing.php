<?php
// Tests en ligne de commande des types de calcul (forfait / tarif du plugin / grille personnalisée)
// (les contraintes globales sont testées dans tests/constraints.php).
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/carriers-pricing.php
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

use Grav\Plugin\SimpleShop\Delivery\{CarrierCatalog, CarrierConfig, CarrierProviderInterface, ModeEditor, ShippingConstraints, ShippingManager};

final class FakeProvider implements CarrierProviderInterface
{
    public function getId(): string { return 'fake'; }
    public function getLabel(): string { return 'Fake'; }
    public function getModes(): array
    {
        return ['dom' => [
            'name' => 'Domicile', 'enabled' => true, 'margin' => ['fixed' => 1, 'percent' => 0],
            'rules' => [
                ['zone' => 'FR-METRO', 'min_weight' => 0, 'max_weight' => 500, 'price' => 7.0],
                ['zone' => 'FR-METRO', 'min_weight' => 501, 'max_weight' => 30000, 'price' => 10.0],
            ],
        ]];
    }
    public function findPickupPoints(string $modeKey, array $address, array $metrics): array { return []; }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
}

$config = ['taxes' => ['default' => 'TVA_5_5'], 'delivery' => ['prices_entered' => 'ht']];
$small = ['subtotal' => 12.95, 'subtotals' => ['book' => 12.95], 'weight' => 340, 'thickness' => 29];
$big = ['subtotal' => 40.0, 'subtotals' => ['book' => 40.0], 'weight' => 900, 'thickness' => 60];
$floor = ShippingConstraints::normalize([['name' => 'Plancher', 'product_types' => ['book'], 'rules' => [
    ['max_amount' => 34.99, 'type' => 'minimum', 'value' => 3], ['min_amount' => 35, 'type' => 'minimum', 'value' => 0.01]]]]);
$price = fn (array $mode, array $m, array $c = []) => ShippingManager::quoteMode($mode, 'FR-METRO', $m, $config, false, $c)['price'] ?? null;
$providers = ['fake' => new FakeProvider()];

// --- Catalogue : valeurs par défaut du type de calcul
$cat = CarrierCatalog::build($providers, ['old' => ['name' => 'Ancien', 'enabled' => true, 'rules' => [['zone' => 'FR-METRO', 'price' => 4]]]]);
assert($cat['fake.dom']['pricing'] === 'provider');
assert($cat['old']['pricing'] === 'grid', 'mode manuel existant : grille');
// Ancien format : surcharge de grille sans « pricing » = grille personnalisée
$cat = CarrierCatalog::build($providers, ['fake.dom' => ['rules' => [['zone' => 'FR-METRO', 'price' => 5]]]]);
assert($cat['fake.dom']['pricing'] === 'grid' && $cat['fake.dom']['rules'][0]['price'] == 5);
// Retour au tarif du plugin : la grille surchargée est ignorée
$cat = CarrierCatalog::build($providers, ['fake.dom' => ['pricing' => 'provider', 'rules' => [['zone' => 'FR-METRO', 'price' => 5]]]]);
assert($cat['fake.dom']['rules'][0]['price'] == 7.0);

// --- Forfait : un seul prix, sans marge, TVA puis plancher légal
$flat = CarrierCatalog::build([], ['f' => ['name' => 'Forfait', 'enabled' => true, 'pricing' => 'flat', 'flat_price' => 5, 'margin' => ['fixed' => 2]]])['f'];
assert($price($flat, $small) === 5.28, 'forfait 5 € HT → 5,28 € TTC, marge ignorée');
assert($price($flat, $big) === 5.28, 'forfait : même prix quel que soit le poids');
$flat['free_from'] = 35.0;
assert($price($flat, $big) === 0.0, 'offert dès 35 €, sans contrainte : 0 €');
assert($price($flat, $big, $floor) === 0.01, 'offert dès 35 € → plancher 0,01 €');
// Forfait du plugin
$cat = CarrierCatalog::build($providers, ['fake.dom' => ['pricing' => 'flat', 'flat_price' => 6]]);
assert($price($cat['fake.dom'], $big) === 6.33);
// Tarif du plugin : grille + marge du plugin
$cat = CarrierCatalog::build($providers, []);
assert($price($cat['fake.dom'], $small) === round((7 + 1) * 1.055, 2));

// --- Forfait 0 € + plancher = 3,00 € / 0,01 € (ancien préréglage, désormais une contrainte globale)
$zero = CarrierCatalog::build([], ['z' => ['name' => 'Z', 'enabled' => true, 'pricing' => 'flat', 'flat_price' => 0]])['z'];
assert($price($zero, $small, $floor) === 3.0 && $price($zero, $big, $floor) === 0.01);

// --- ModeEditor : validation et stockage
$base = ['name' => 'X', 'enabled' => true, 'rules' => []];
[, $e] = ModeEditor::sanitize($base + ['pricing' => 'flat'], false);
assert($e !== [], 'forfait sans prix refusé');
[, $e] = ModeEditor::sanitize($base + ['pricing' => 'grid'], false);
assert($e !== [], 'grille vide refusée');
[, $e] = ModeEditor::sanitize($base + ['pricing' => 'provider'], false);
assert($e !== [], 'tarif du plugin interdit pour un mode manuel');
[$c, $e] = ModeEditor::sanitize($base + ['pricing' => 'flat', 'flat_price' => '4,5'], false);
assert($e === [] && $c['flat_price'] == 4.5);
$stored = ModeEditor::toStorage($c, null);
assert($stored['pricing'] === 'flat' && !isset($stored['margin']));
// Mode du plugin passé en forfait : seuls pricing et flat_price sont enregistrés
$defaults = CarrierCatalog::build($providers, [])['fake.dom']['provider_defaults'];
$in = $defaults;
$in['pricing'] = 'flat';
$in['flat_price'] = 6;
[$c, $e] = ModeEditor::sanitize($in, true);
assert($e === []);
assert(ModeEditor::toStorage($c, $defaults) == ['pricing' => 'flat', 'flat_price' => 6.0]);
// Tarif du plugin sans changement : rien à enregistrer
[$c] = ModeEditor::sanitize($defaults, true);
assert(ModeEditor::toStorage($c, $defaults) === []);

// --- CarrierConfig : nouveaux champs et bloc setup
if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
    assert(CarrierConfig::validate(CarrierConfig::dump(['f' => ['name' => 'F', 'pricing' => 'flat', 'flat_price' => 0]], ShippingConstraints::toStorage($floor))) === []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: flat }\n") !== []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: provider, rules: [] }\n") !== []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: autre }\n") !== []);
}

echo "OK\n";
