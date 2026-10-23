<?php
// Tableau d'édition rapide du catalogue (Admin2 > Ventes > Catalogue) : colonnes déclarées par le blueprint du type de produit,
// champs et contraintes repris de leur définition, validation de Grav, écriture des seuls champs modifiés, conflit si la fiche
// a changé depuis l'affichage, verrou du déstockage.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/catalog-table.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Catalog\CatalogTable;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Product\ProductTypes;
use SimpleShopTest\FakePage;

$pages = [
    new FakePage('/affiches/b', ['title' => 'Bateau', 'subtitle' => 'Grand format', 'product_price' => '12.50', 'product_quantity' => '7', 'format' => 'A2', 'framed' => false], 'poster'),
    new FakePage('/affiches/a', ['title' => 'Éléphant', 'product_price' => '9', 'product_quantity' => '3', 'price_entered_as' => 'ht'], 'poster'),
    new FakePage('/divers/x', ['title' => 'Autre type'], 'default'),
];
$grav = \SimpleShopTest\boot([], $pages, null);
$grav['language'] = new class {
    public function translate($k) { return is_array($k) ? implode(' ', $k) : (string) $k; }
};
ProductTypes::set([
    'poster' => ['price_calculation' => 'Grav\Plugin\SimpleShop\Product\GenericProduct::price', 'price_fields' => ['product_price'],
        'admin_table' => ['columns' => [['title', 'subtitle'], 'format', 'framed', 'product_price', 'product_quantity', 'inconnu', 'image']]],
    'sans_tableau' => ['price_calculation' => 'Grav\Plugin\SimpleShop\Product\GenericProduct::price'],
]);
// Définitions du blueprint (header.<nom>), comme Grav les rend (schema()->getProperty())
$blueprint = [
    'title'            => ['type' => 'text', 'label' => 'Titre', 'validate' => ['required' => true]],
    'subtitle'         => ['type' => 'text', 'label' => 'Sous-titre'],
    'format'           => ['type' => 'select', 'label' => 'Format', 'options' => ['A3' => 'A3', 'A2' => 'A2']],
    'framed'           => ['type' => 'toggle', 'label' => 'Encadrée'],
    'product_price'    => ['type' => 'text', 'label' => 'Prix', 'attributes' => ['inputmode' => 'decimal'],
        'validate' => ['rule' => 'regex', 'pattern' => '^[0-9]+([.,][0-9]{1,2})?$']],
    'product_quantity' => ['type' => 'text', 'label' => 'Stock', 'append' => 'ex.', 'validate' => ['pattern' => '^-?[0-9]+$']],
    'image'            => ['type' => 'file', 'label' => 'Image'],
];
$defs = fn (string $name) => $blueprint[$name] ?? null;
$validate = static function (string $value, array $definition): array {
    $out = [];
    foreach (\Grav\Common\Data\Validation::validate($value, $definition) as $list) {
        foreach ((array) $list as $m) {
            $out[] = (string) $m;
        }
    }
    return $out;
};

// --- 1. Types et colonnes
$types = CatalogTable::types();
assert(array_keys($types) === ['poster'], 'seuls les types qui déclarent un tableau');
$cols = CatalogTable::columns($types['poster'], $defs, 'ttc');
assert(count($cols['columns']) === 5, 'colonnes valides : ' . count($cols['columns']));
assert(array_column($cols['columns'][0]['fields'], 'name') === ['title', 'subtitle'], 'champs empilés');
assert(str_contains(implode(' ', $cols['errors']), '« inconnu » absent') && str_contains(implode(' ', $cols['errors']), 'type « file »'), implode(' | ', $cols['errors']));
$f = fn (string $name) => array_values(array_filter(array_merge(...array_map(fn ($c) => $c['fields'], $cols['columns'])), fn ($x) => $x['name'] === $name))[0];
assert($f('title')['required'] === true, 'obligatoire repris du blueprint');
assert($f('product_price')['pattern'] === '[0-9]+([.,][0-9]{1,2})?' && $f('product_price')['price_basis'] === 'ttc' && $f('product_price')['inputmode'] === 'decimal');
assert($f('product_quantity')['append'] === 'ex.' && $f('product_quantity')['price_basis'] === null);
assert($f('format')['type'] === 'select' && array_column($f('format')['options'], 'value') === ['A3', 'A2']);
assert($f('framed')['type'] === 'toggle');

// --- 2. Lignes (triées par titre, sans accents), base de prix propre à la fiche
$rows = CatalogTable::rows($grav['pages']->all(), $types['poster'], $cols['columns']);
assert(array_column($rows, 'route') === ['/affiches/b', '/affiches/a'], json_encode(array_column($rows, 'title')));
assert($rows[0]['values'] === ['title' => 'Bateau', 'subtitle' => 'Grand format', 'format' => 'A2', 'framed' => '0', 'product_price' => '12.50', 'product_quantity' => '7']);
assert($rows[1]['price_entered_as'] === 'ht' && $rows[1]['values']['subtitle'] === '');

// --- 3. Enregistrement
$file = $pages[0]->file;
$lock = $grav['locator']->findResource('user://data/simple-shop', true, true) . '/stock.lock';
$body = "\nTexte de la fiche, inchangé.\n";
file_put_contents($file, file_get_contents($file) . $body);
$save = fn (array $changes, array $original) => CatalogTable::save($file, $changes, $original, $cols['columns'], $validate, $lock);

// Contraintes du blueprint (validation de Grav) : rien n'est écrit
$before = file_get_contents($file);
$r = $save(['product_price' => '12,505', 'title' => '', 'format' => 'A0', 'image' => 'x'], []);
assert(!$r['ok'] && isset($r['errors']['product_price'], $r['errors']['title'], $r['errors']['format'], $r['errors']['image']), json_encode($r));
assert(file_get_contents($file) === $before, 'fiche intacte après refus');

// Modification valide : seuls les champs modifiés, le reste de la fiche (autres clés, texte) conservé
$r = $save(['product_price' => '13.00', 'subtitle' => 'Très grand format', 'framed' => '1'], ['product_price' => '12.50', 'subtitle' => 'Grand format', 'framed' => '0']);
assert($r['ok'] && $r['values']['product_price'] === '13.00' && $r['values']['framed'] === '1', json_encode($r));
$content = file_get_contents($file);
$hdr = Symfony\Component\Yaml\Yaml::parse(explode('---', $content)[1]);
assert($hdr['product_price'] === '13.00' && $hdr['subtitle'] === 'Très grand format' && $hdr['framed'] === true && $hdr['product_quantity'] === '7' && $hdr['title'] === 'Bateau');
assert(str_ends_with($content, $body), 'texte de la fiche conservé');

// Le déstockage d'une commande relit la ligne product_quantity telle qu'écrite par le tableau
assert(InventoryManager::decrementStockInFile($file, 2, $lock) === ['old' => 7, 'new' => 5]);

// Conflit : le stock affiché (7) n'est plus celui de la fiche (5) → rien n'est écrit, valeur actuelle renvoyée
$r = $save(['product_quantity' => '20', 'subtitle' => 'Autre'], ['product_quantity' => '7', 'subtitle' => 'Très grand format']);
assert(!$r['ok'] && $r['conflicts'] === ['product_quantity' => '5'], json_encode($r));
assert(Symfony\Component\Yaml\Yaml::parse(explode('---', file_get_contents($file))[1])['subtitle'] === 'Très grand format', 'rien écrit en cas de conflit');
// En connaissance de cause (valeur actuelle reprise)
$r = $save(['product_quantity' => '20'], ['product_quantity' => '5']);
assert($r['ok'] && $r['values']['product_quantity'] === '20');
assert(InventoryManager::decrementStockInFile($file, 1, $lock) === ['old' => 20, 'new' => 19], 'stock relu par le déstockage');

// Champ hors tableau refusé (même s'il existe dans la fiche)
$r = $save(['price_entered_as' => 'ht'], []);
assert(!$r['ok'] && isset($r['errors']['price_entered_as']));

// --- 4. Formulaire complet (envoi sans JavaScript) : seules les fiches modifiées sont réécrites
$fileA = $pages[1]->file;
$mtimeA = filemtime($fileA);
$resolve = fn (string $route) => ['/affiches/b' => $file, '/affiches/a' => $fileA][$route] ?? null;
$current = fn (string $f) => Symfony\Component\Yaml\Yaml::parse(explode('---', file_get_contents($f))[1]);
$b = $current($file);
$form = [   // ce que le navigateur envoie : toutes les lignes, toutes les valeurs, et les valeurs d'origine en champs cachés
    ['route' => '/affiches/b', 'values' => ['title' => 'Bateau', 'subtitle' => $b['subtitle'], 'format' => 'A3', 'framed' => '1',
        'product_price' => '13.00', 'product_quantity' => '19'],
     'original' => ['title' => 'Bateau', 'subtitle' => $b['subtitle'], 'format' => 'A2', 'framed' => '1', 'product_price' => '13.00', 'product_quantity' => '19']],
    ['route' => '/affiches/a', 'values' => ['title' => 'Éléphant', 'subtitle' => '', 'format' => '', 'framed' => '0', 'product_price' => '9', 'product_quantity' => '3'],
     'original' => ['title' => 'Éléphant', 'subtitle' => '', 'format' => '', 'framed' => '0', 'product_price' => '9', 'product_quantity' => '3']],
    ['route' => '/affiches/inconnue', 'values' => ['title' => 'X'], 'original' => ['title' => 'X']],   // inchangée : ignorée
];
clearstatcache();
$r = CatalogTable::saveItems($form, $resolve, $cols['columns'], $validate, $lock);
assert($r['saved'] === 1 && $r['unchanged'] === 2 && $r['failed'] === 0 && array_keys($r['items']) === ['/affiches/b'], json_encode($r));
assert($current($file)['format'] === 'A3', 'fiche modifiée réécrite');
clearstatcache();
assert(filemtime($fileA) === $mtimeA && !isset($current($fileA)['format']), 'fiche inchangée : fichier non touché');

// Envoi optimisé (JavaScript) : seulement la fiche et les champs modifiés, même format ; erreurs par fiche
$r = CatalogTable::saveItems([
    ['route' => '/affiches/a', 'values' => ['product_price' => '10.5'], 'original' => ['product_price' => '9']],
    ['route' => '/affiches/b', 'values' => ['product_price' => 'abc'], 'original' => ['product_price' => '13.00']],
    ['route' => '/affiches/zz', 'values' => ['title' => 'Y'], 'original' => ['title' => 'X']],
], $resolve, $cols['columns'], $validate, $lock);
assert($r['saved'] === 1 && $r['failed'] === 2 && $r['items']['/affiches/a']['ok'] && isset($r['items']['/affiches/b']['errors']['product_price'], $r['items']['/affiches/zz']), json_encode($r));
assert($current($fileA)['product_price'] === '10.5' && $current($file)['product_price'] === '13.00');

echo "OK\n";
