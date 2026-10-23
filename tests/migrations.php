<?php
// Mise à jour du plugin sur une base existante : pour CHAQUE schéma publié (tests/fixtures/schemas/, un fichier par version
// ayant modifié la structure), une base remplie de données de démonstration est migrée par le code courant. On vérifie
// qu'aucune donnée n'est perdue, que tout l'écran d'administration fonctionne dessus et que la migration est rejouable.
// Règle (AGENTS.md) : tout changement de structure ajoute ici le schéma de la version précédente.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/migrations.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager as D;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger as L;

/** Insère une ligne avec les seules colonnes que la table possède à cette version. */
function insertRow(PDO $db, string $table, array $row): void
{
    $cols = array_column($db->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if ($cols === []) {
        return;
    }
    $row = array_intersect_key($row, array_flip($cols));
    $db->prepare('INSERT INTO "' . $table . '" (' . implode(', ', array_map(fn ($c) => '"' . $c . '"', array_keys($row))) . ') VALUES ('
        . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}

function count_(PDO $db, string $table): int
{
    $exists = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $db->quote($table))->fetchColumn();

    return $exists ? (int) $db->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn() : 0;
}

$customer = json_encode(['email' => 'eloise@exemple.fr', 'firstname' => 'Éloïse', 'lastname' => 'Martin', 'street' => '1 rue', 'postal-code' => '75001', 'city' => 'Paris']);
$fixtures = glob(__DIR__ . '/fixtures/schemas/*.sql');
sort($fixtures);
assert(count($fixtures) >= 7, 'schémas historiques présents');

foreach ($fixtures as $fixture) {
    $name = basename($fixture, '.sql');
    $grav = \SimpleShopTest\boot(['invoicing' => ['manual' => true],
        'seller' => ['name' => 'Vendeur', 'address_line1' => '1 rue', 'postal_code' => '75001', 'city' => 'Paris', 'country' => 'FR', 'siret' => '12345678900011']], [], null);
    $dir = $grav['locator']->findResource('user://data/simple-shop', true, true);
    $dbPath = $dir . '/shop.db';
    @unlink($dbPath);
    @unlink($dir . '/.shop-schema-applied');

    // --- Base telle que l'ancienne version l'a laissée, avec des données
    $old = new PDO('sqlite:' . $dbPath);
    $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $old->exec(file_get_contents($fixture));
    // Colonne ajoutée à la main ou par une version future : la reconstruction de la table `invoice` doit la garder.
    $old->exec('ALTER TABLE invoice ADD COLUMN note_libre TEXT');
    insertRow($old, 'cart', ['cart_id' => 'c1', 'expires_at' => '2099-01-01 00:00:00']);
    insertRow($old, 'cart_item', ['cart_id' => 'c1', 'product_route' => '/livres/a', 'quantity' => 2]);
    insertRow($old, 'cart_line', ['cart_id' => 'c1', 'line_id' => 'abcdef0123456789', 'product_route' => '/livres/b', 'options' => '{}', 'quantity' => 1]);
    foreach (['CMD-OLD-PAID' => 'paid', 'CMD-OLD-SHIP' => 'shipped', 'CMD-OLD-PROC' => 'processing'] as $ref => $status) {
        insertRow($old, 'order', ['order_reference' => $ref, 'created_at' => '2026-10-10 10:00:00', 'customer_data' => $customer, 'shipping_data' => '{}',
            'billing_data' => '{}', 'total_amount' => 25.9, 'status' => $status, 'currency' => 'EUR', 'total_cents' => 2590]);
        insertRow($old, 'order_item', ['order_reference' => $ref, 'product_route' => '/livres/a', 'title' => 'Livre A', 'price' => 20.9, 'quantity' => 1,
            'unit_ht_cents' => 1981, 'unit_ttc_cents' => 2090, 'tax_code' => 'taux-reduit', 'tax_bp' => 550, 'product_type' => 'book']);
        insertRow($old, 'order_payment', ['payment_reference' => 'pi_' . strtolower(substr($ref, -4)), 'order_reference' => $ref]);
        insertRow($old, 'order_status_history', ['order_reference' => $ref, 'old_status' => null, 'new_status' => $status]);
    }
    insertRow($old, 'invoice', ['order_reference' => 'CMD-OLD-PAID', 'invoice_number' => 'FACT-' . date('Y') . '-00007', 'customer_type' => 'b2c',
        'ereporting_status' => 'disabled', 'vat_rate' => 5.5, 'pdf_path' => '/x/FACT.pdf', 'note_libre' => 'à garder']);
    insertRow($old, 'order_tracking', ['order_reference' => 'CMD-OLD-SHIP', 'tracking_number' => '6A12345678901']);
    insertRow($old, 'payment', ['order_reference' => 'CMD-OLD-PROC', 'amount_cents' => 2590, 'currency' => 'EUR', 'method' => 'stripe',
        'paid_at' => '2026-10-10 10:00:00', 'recorded_by' => 'client', 'external_ref' => 'pi_proc']);
    $before = [];
    foreach (['order', 'order_item', 'invoice', 'order_payment', 'order_status_history'] as $t) {
        $before[$t] = count_($old, $t);
    }
    $old = null;

    // --- Migration par le code courant
    $db = InventoryManager::getDatabase();
    assert(is_file($dir . '/.shop-schema-applied'), "$name : migration complète (repère écrit) — " . json_encode($grav['log']->lines));
    assert(count(glob($dir . '/backups/shop-*.db') ?: []) === 1, "$name : copie de la base avant migration");
    foreach (['order', 'order_item', 'invoice', 'order_payment'] as $t) {
        assert(count_($db, $t) === $before[$t], "$name : $t conservé");
    }
    $cols = fn (string $t) => array_column($db->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['source', 'payload', 'total_cents'] as $c) {
        assert(in_array($c, $cols('order'), true), "$name : order.$c");
    }
    foreach (['kind', 'due_cents', 'access_token', 'payload', 'pdf_path', 'vat_rate'] as $c) {
        assert(in_array($c, $cols('invoice'), true), "$name : invoice.$c");
    }
    $inv = $db->query("SELECT * FROM invoice")->fetch(PDO::FETCH_ASSOC);
    assert($inv['invoice_number'] === 'FACT-' . date('Y') . '-00007' && $inv['pdf_path'] === '/x/FACT.pdf' && $inv['kind'] === 'invoice'
        && $inv['note_libre'] === 'à garder', "$name : facture intacte");
    $fk = $db->query("PRAGMA foreign_key_list('invoice')")->fetchAll(PDO::FETCH_ASSOC);
    assert(count($fk) === 1 && $fk[0]['table'] === 'order' && $fk[0]['on_delete'] === 'CASCADE', "$name : clé étrangère de la facture gardée");
    try {
        $db->exec("INSERT INTO invoice (order_reference, invoice_number) VALUES ('CMD-OLD-PAID', 'FACT-" . date('Y') . "-00007')");
        assert(false, "$name : numéro de facture toujours unique");
    } catch (PDOException $e) {
    }
    assert(count_($db, 'cart_item') === 0 && count_($db, 'cart_line') >= 1, "$name : panier repris");

    // Ancien statut « payée » → en cours + un paiement repris ; les autres commandes inchangées
    $paid = OrderManager::getOrder('CMD-OLD-PAID');
    $st = L::orderSettlement($paid);
assert($paid['status'] === 'processing' && $st['state'] === 'paid', "$name : " . json_encode([$paid['status'], $st]));
    assert(OrderManager::getOrder('CMD-OLD-SHIP')['status'] === 'shipped');
    $legacy = L::forOrder('CMD-OLD-PAID');
    assert(count($legacy) === 1 && $legacy[0]['amount_cents'] === 2590 && $legacy[0]['external_ref'] === 'pi_paid', "$name : paiement repris " . json_encode($legacy));

    // Écrans d'administration sur la base migrée
    $list = OrderManager::search([], 25, 0);
    assert($list['total'] === 3 && count($list['items']) === 3, "$name : liste des commandes");
    foreach ($list['items'] as $o) {
        assert(isset($o['settlement']['state']), "$name : état de règlement de " . $o['order_reference']);
    }
    assert(OrderManager::search(['q' => 'eloise'], 25, 0)['total'] === 3, "$name : recherche sans accents");
    assert(str_contains(OrderManager::exportCsv([]), 'CMD-OLD-SHIP'), "$name : export CSV");
    $docs = D::search([], 25, 0);
    assert($docs['total'] === 1 && $docs['items'][0]['settlement']['state'] === 'paid', "$name : onglet Factures " . json_encode($docs['items'][0]['settlement'] ?? null));

    // Fonctions récentes sur la base migrée : vente manuelle, numérotation qui continue
    $r = D::saveDraft(['customer' => ['type' => 'b2b', 'email' => 'acme@exemple.fr', 'company' => 'ACME', 'street' => '1 rue', 'postal-code' => '69001', 'city' => 'Lyon'],
        'lines' => [['title' => 'Prestation', 'quantity' => 1, 'unit_price' => '100']]], 'admin');
    assert($r['errors'] === [], "$name : " . json_encode($r['errors']));
    $issued = D::issue($r['reference'], 'invoice', ['issued_by' => 'admin']);
    assert($issued['errors'] === [] && $issued['document']['invoice_number'] === 'FACT-' . date('Y') . '-00008', "$name : numéro suivant " . json_encode($issued['errors'] ?: $issued['document']['invoice_number']));
    assert(OrderManager::getOrder($r['reference'])['status'] === 'pending', "$name : jamais l'ancien statut par défaut « paid »");

    // Rejouer la migration (repère perdu, déploiement relancé…) : rien n'est dupliqué
    $counts = [count_($db, 'payment'), count_($db, 'order_status_history'), count_($db, 'invoice')];
    $db = null;
    unlink($dir . '/.shop-schema-applied');
    $db = InventoryManager::getDatabase();
    assert([count_($db, 'payment'), count_($db, 'order_status_history'), count_($db, 'invoice')] === $counts, "$name : migration rejouable sans doublon");
    assert(is_file($dir . '/.shop-schema-applied'));
    echo "  $name : OK\n";
}

// Base neuve (première installation) : créée directement au schéma courant, sans copie de sauvegarde
$grav = \SimpleShopTest\boot([], [], null);
$dir = $grav['locator']->findResource('user://data/simple-shop', true, true);
@unlink($dir . '/shop.db');
@unlink($dir . '/.shop-schema-applied');
exec('rm -rf ' . escapeshellarg($dir . '/backups'));
$db = InventoryManager::getDatabase();
assert(is_file($dir . '/.shop-schema-applied') && !is_dir($dir . '/backups'), 'base neuve');
assert(count_($db, 'payment') === 0);

echo "OK\n";
