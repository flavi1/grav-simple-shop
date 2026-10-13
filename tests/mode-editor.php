<?php
// Test en ligne de commande du contrôle serveur des modes de livraison (ModeEditor::sanitize),
// appelé par PUT /api/v1/simple-shop/carriers/{key} avant toute écriture (erreur 422 sinon).
// Lancement : php -d zend.assertions=1 -d assert.exception=1 tests/mode-editor.php
// Variable YAML facultative : chemin d'un autoload de symfony/yaml si la classe n'est pas déjà chargeable.
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

use Grav\Plugin\SimpleShop\Delivery\ModeEditor;

$base = [
    'name' => 'Lettre suivie', 'enabled' => true, 'legal_floor' => true,
    'margin' => ['fixed' => 0, 'percent' => 0], 'free_from' => null,
    'constraints' => ['max_weight' => null, 'max_thickness' => null],
    'rules' => [['zone' => 'FR-METRO', 'min_weight' => 0, 'max_weight' => null, 'min_basket' => 0, 'max_basket' => null, 'max_thickness' => null, 'price' => '1.52']],
];

// Marges invalides : refusées, quel que soit le client (le navigateur bloque déjà -1, pas un appel direct à l'API).
foreach ([['fixed', -1], ['fixed', 'abc'], ['percent', -5], ['percent', 'x'], ['fixed', 1001]] as [$k, $v]) {
    $in = $base;
    $in['margin'][$k] = $v;
    [, $errors] = ModeEditor::sanitize($in, false);
    assert($errors !== [], "margin.$k=" . var_export($v, true) . ' aurait dû être refusé');
}

// Valeurs valides : virgule décimale acceptée, valeurs converties en nombres.
$in = $base;
$in['margin']['fixed'] = '0,6';
[$clean, $errors] = ModeEditor::sanitize($in, false);
assert($errors === [] && abs($clean['margin']['fixed'] - 0.6) < 1e-9);

// Grille : prix manquant ou négatif refusé.
foreach (['', '-1', 'abc'] as $price) {
    $in = $base;
    $in['rules'][0]['price'] = $price;
    [, $errors] = ModeEditor::sanitize($in, false);
    assert($errors !== [], 'prix ' . var_export($price, true) . ' aurait dû être refusé');
}

echo "OK\n";
