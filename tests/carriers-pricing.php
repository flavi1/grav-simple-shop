<?php
// Tests en ligne de commande des types de calcul (forfait / tarif du plugin / grille personnalisée)
// et du préréglage « Livres France ».
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

use Grav\Plugin\SimpleShop\Delivery\{CarrierCatalog, CarrierConfig, CarrierProviderInterface, ModeEditor, ShippingManager};

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

$config = ['taxes' => ['default' => 'TVA_5_5'], 'delivery' => ['prices_entered' => 'ht', 'book_law' => ['enabled' => true]]];
$small = ['subtotal' => 12.95, 'weight' => 340, 'thickness' => 29];
$big = ['subtotal' => 40.0, 'weight' => 900, 'thickness' => 60];
$price = fn (array $mode, array $m) => ShippingManager::quoteMode($mode, 'FR-METRO', $m, $config)['price'] ?? null;
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
assert($price($flat, $big) === 0.01, 'offert dès 35 € → plancher 0,01 €');
// Forfait du plugin
$cat = CarrierCatalog::build($providers, ['fake.dom' => ['pricing' => 'flat', 'flat_price' => 6]]);
assert($price($cat['fake.dom'], $big) === 6.33);
// Tarif du plugin : grille + marge du plugin
$cat = CarrierCatalog::build($providers, []);
assert($price($cat['fake.dom'], $small) === round((7 + 1) * 1.055, 2));

// --- Préréglage « Livres France » : forfait 0 € soumis au plancher → 3,00 € / 0,01 €
$preset = ModeEditor::booksPreset(['enabled' => true, 'threshold' => 35, 'below' => 3, 'at_or_above' => 0.01]);
assert($preset['description'] === '3,00 € pour moins de 35,00 € de livres, 0,01 € au-delà.');
$p = CarrierCatalog::build([], [ModeEditor::BOOKS_PRESET_KEY => $preset])[ModeEditor::BOOKS_PRESET_KEY];
assert($price($p, $small) === 3.0 && $price($p, $big) === 0.01);

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
    assert(CarrierConfig::validate(CarrierConfig::dump(['f' => ['name' => 'F', 'pricing' => 'flat', 'flat_price' => 0]], ['books_preset' => 'applied'])) === []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: flat }\n") !== []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: provider, rules: [] }\n") !== []);
    assert(CarrierConfig::validate("carriers:\n  f: { name: F, pricing: autre }\n") !== []);
}

echo "OK\n";
