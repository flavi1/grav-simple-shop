<?php
// Tests en ligne de commande du retrait sur place : géocodeur (réponse simulée), distance, éligibilité, saisie.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/local-pickup.php
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

use Grav\Plugin\SimpleShop\Delivery\{CarrierCatalog, CarrierConfig, Geocoder, ModeEditor, ShippingConstraints, ShippingManager};

// --- Géocodeur : réponse au format du service (GeoJSON, coordonnées [lon, lat])
$seen = [];
$reply = function (array $features, int $status = 200) use (&$seen) {
    return function (string $url, int $t) use ($features, $status, &$seen) {
        $seen[] = $url;
        return ['status' => $status, 'body' => json_encode(['type' => 'FeatureCollection', 'features' => $features])];
    };
};
$amiens = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [2.290084, 49.897442]],
    'properties' => ['label' => '8 Boulevard du Port 80000 Amiens', 'score' => 0.65, 'postcode' => '80000', 'city' => 'Amiens']];
$g = new Geocoder([], $reply([$amiens]));
$r = $g->geocodeAddress('8 bd du Port', '80000', 'Amiens');
assert($r === ['lat' => 49.897442, 'lon' => 2.290084, 'label' => '8 Boulevard du Port 80000 Amiens', 'score' => 0.65]);
assert(strpos($seen[0], Geocoder::DEFAULT_URL . '?q=8%20bd%20du%20Port%2080000%20Amiens&limit=1&postcode=80000') === 0, $seen[0]);
$g->geocodeTown('80000', 'Amiens');
assert(strpos($seen[1], 'q=80000%20Amiens') !== false && strpos($seen[1], 'Port') === false, 'client : commune seulement');
$low = $amiens; $low['properties']['score'] = 0.2;
assert((new Geocoder([], $reply([$low])))->geocodeTown('80000', 'Amiens') === null, 'score trop faible');
assert((new Geocoder([], $reply([])))->geocodeTown('99999', 'Nulle-part') === null, 'aucun résultat');
$failed = false;
try { (new Geocoder([], $reply([], 503)))->geocodeTown('80000', 'Amiens'); } catch (\RuntimeException $e) { $failed = true; }
assert($failed, 'service en erreur : exception');
assert((new Geocoder(['url' => 'http://insecure.example/search'], $reply([$amiens]))) instanceof Geocoder);
(new Geocoder(['url' => 'http://insecure.example/search'], function ($url) use (&$seen) { $seen[] = $url; return ['status' => 200, 'body' => '{"features":[]}']; }))->geocodeTown('80000', 'Amiens');
assert(strpos(end($seen), Geocoder::DEFAULT_URL) === 0, 'URL non https : service par défaut');

// --- Distance (Paris – Lyon ≈ 392 km à vol d'oiseau)
$d = Geocoder::distanceKm(48.8566, 2.3522, 45.7640, 4.8357);
assert($d > 385 && $d < 400, (string) $d);

// --- Éligibilité et prix
$config = ['taxes' => ['default' => 'TVA_5_5'], 'delivery' => ['prices_entered' => 'ht']];
$mode = CarrierCatalog::build([], ['atelier' => [
    'name' => 'Retrait à l’atelier', 'enabled' => true, 'kind' => 'local_pickup', 'flat_price' => 0, 'max_distance_km' => 20,
    'pickup_address' => ['street' => '8 bd du Port', 'postal_code' => '80000', 'city' => 'Amiens'], 'geo' => ['lat' => 49.897442, 'lon' => 2.290084],
    'rules' => [['zone' => 'FR-METRO', 'price' => 99]],   // ignorée
]])['atelier'];
assert($mode['kind'] === 'local_pickup' && $mode['pricing'] === 'flat' && $mode['rules'] === []);
$near = ['weight' => 99999, 'thickness' => 999, 'subtotal' => 12.95, 'subtotals' => ['book' => 12.95], 'destination' => ['lat' => 49.88, 'lon' => 2.35]];   // ~5 km
$far = ['destination' => ['lat' => 48.8566, 'lon' => 2.3522]] + $near;   // Paris, ~115 km
$q = ShippingManager::evaluate($mode, 'FR-METRO', $near, $config);
assert($q['quote']['price'] === 0.0 && $q['quote']['distance_km'] > 3 && $q['quote']['distance_km'] < 7, 'proche : proposé, gratuit, sans limite de poids');
$q = ShippingManager::evaluate($mode, 'FR-METRO', $far, $config);
assert($q['quote'] === null && strpos($q['reason'], 'au plus 20') !== false, $q['reason']);
$unknown = $near; unset($unknown['destination']);
assert(ShippingManager::evaluate($mode, 'FR-METRO', $unknown, $config)['quote'] === null, 'client non localisé : non proposé');
$nogeo = $mode; $nogeo['geo'] = null;
assert(ShippingManager::evaluate($nogeo, 'FR-METRO', $near, $config)['quote'] === null, 'lieu non localisé : non proposé');
$paid = $mode; $paid['flat_price'] = 2.0;
assert(ShippingManager::evaluate($paid, 'FR-METRO', $near, $config)['quote']['price'] === 2.11, 'prix HT + TVA');
// Contraintes globales : appliquées sauf exclusion du mode
$darcos = ShippingConstraints::normalize([['name' => 'D', 'product_types' => ['book'], 'rules' => [['max_amount' => 34.99, 'type' => 'minimum', 'value' => 3]]]]);
assert(ShippingManager::evaluate($mode, 'FR-METRO', $near, $config, false, $darcos)['quote']['price'] === 3.0);
$darcos[0]['except_modes'] = ['atelier'];
assert(ShippingManager::evaluate($mode, 'FR-METRO', $near, $config, false, $darcos)['quote']['price'] === 0.0);

// --- Saisie dans l'administration
[$c, $e] = ModeEditor::sanitize(['kind' => 'local_pickup', 'name' => 'Atelier', 'enabled' => true, 'flat_price' => '', 'max_distance_km' => '15,5',
    'pickup_address' => ['street' => '1 rue X', 'postal_code' => '80000', 'city' => 'Amiens']], false);
assert($e === [] && $c['flat_price'] === 0.0 && $c['max_distance_km'] === 15.5);
assert(ModeEditor::toStorage($c, null) === $c);
[, $e] = ModeEditor::sanitize(['kind' => 'local_pickup', 'name' => 'Atelier', 'enabled' => true], false);
assert(count($e) === 2, 'actif : adresse et distance obligatoires');
[, $e] = ModeEditor::sanitize(['kind' => 'local_pickup', 'name' => 'Atelier', 'enabled' => false], false);
assert($e === [], 'inactif : adresse facultative');
[, $e] = ModeEditor::sanitize(['kind' => 'local_pickup', 'name' => 'A', 'pickup_address' => ['postal_code' => '8000']], false);
assert($e !== [], 'code postal à 5 chiffres');

if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
    assert(CarrierConfig::validate(CarrierConfig::dump(['atelier' => $c + ['geo' => ['lat' => 1.0, 'lon' => 2.0]]], [])) === []);
    assert(CarrierConfig::validate("carriers:\n  a.b: { kind: local_pickup }\n") !== []);
    assert(CarrierConfig::validate("carriers:\n  a: { name: A, kind: autre }\n") !== []);
}

echo "OK\n";
