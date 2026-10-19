<?php
// Paiements et état de règlement (Payments\PaymentLedger) ; reprise des anciennes commandes « payées » et de l'ancienne
// table `invoice` (une seule facture par commande).
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/payments.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger as L;

$grav = \SimpleShopTest\boot([], [], null);
$dataDir = $grav['locator']->findResource('user://data/simple-shop', true, true);

// --- 1. Base « d'avant » : facture unique par commande, commande au statut paid avec sa référence Stripe
$old = new PDO('sqlite:' . $dataDir . '/shop.db');
$old->exec('CREATE TABLE "order" (order_reference TEXT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, customer_data TEXT NOT NULL,
    shipping_data TEXT NOT NULL, billing_data TEXT NOT NULL, total_amount REAL NOT NULL, status TEXT DEFAULT \'paid\')');
$old->exec('CREATE TABLE invoice (id INTEGER PRIMARY KEY, order_reference TEXT NOT NULL UNIQUE, invoice_number TEXT NOT NULL UNIQUE,
    customer_type TEXT NOT NULL DEFAULT \'b2c\', ereporting_status TEXT NOT NULL DEFAULT \'not_submitted\', vat_rate REAL NOT NULL DEFAULT 0,
    pdf_path TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$old->exec('CREATE TABLE order_payment (payment_reference TEXT PRIMARY KEY, order_reference TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$old->exec("INSERT INTO \"order\" (order_reference, created_at, customer_data, shipping_data, billing_data, total_amount, status)
    VALUES ('CMD-20261001-AAAAAA', '2026-10-01 10:00:00', '{}', '{}', '{}', 33.89, 'paid'),
           ('CMD-20261002-BBBBBB', '2026-10-02 10:00:00', '{}', '{}', '{}', 12.00, 'shipped')");
$old->exec("INSERT INTO order_payment (payment_reference, order_reference) VALUES ('pi_ancien', 'CMD-20261001-AAAAAA')");
$old->exec("INSERT INTO invoice (order_reference, invoice_number) VALUES ('CMD-20261001-AAAAAA', 'FACT-2026-00001')");
$old = null;

$db = InventoryManager::getDatabase();
$o = $db->query('SELECT status FROM "order" WHERE order_reference = \'CMD-20261001-AAAAAA\'')->fetchColumn();
assert($o === 'processing', 'ancienne commande payée → en préparation');
assert($db->query('SELECT status FROM "order" WHERE order_reference = \'CMD-20261002-BBBBBB\'')->fetchColumn() === 'shipped', 'autres statuts inchangés');
$p = L::forOrder('CMD-20261001-AAAAAA');
assert(count($p) === 1 && $p[0]['amount_cents'] === 3389 && $p[0]['method'] === 'stripe' && $p[0]['external_ref'] === 'pi_ancien'
    && $p[0]['paid_at'] === '2026-10-01 10:00:00' && $p[0]['recorded_by'] === 'reprise', 'paiement repris');
assert(L::forOrder('CMD-20261002-BBBBBB') === [], 'pas de paiement inventé pour une commande non « paid »');
$hist = $db->query('SELECT old_status, new_status FROM order_status_history')->fetchAll(PDO::FETCH_ASSOC);
assert($hist === [['old_status' => 'paid', 'new_status' => 'processing']], 'historique');
// Plusieurs documents par commande : la contrainte UNIQUE(order_reference) a disparu, les données sont conservées.
$inv = $db->query('SELECT * FROM invoice')->fetchAll(PDO::FETCH_ASSOC);
assert(count($inv) === 1 && $inv[0]['invoice_number'] === 'FACT-2026-00001' && $inv[0]['kind'] === 'invoice', 'facture conservée');
$db->exec("INSERT INTO invoice (order_reference, invoice_number, kind) VALUES ('CMD-20261001-AAAAAA', 'FACT-2026-00002', 'credit_note')");
assert((int) $db->query('SELECT COUNT(*) FROM invoice')->fetchColumn() === 2, 'deux documents pour une commande');
// Rejouée (nouvelle version du schéma) : rien n'est dupliqué.
@unlink($dataDir . '/.shop-schema-applied');
$db = InventoryManager::getDatabase();
assert(count(L::forOrder('CMD-20261001-AAAAAA')) === 1 && count($db->query('SELECT * FROM order_status_history')->fetchAll()) === 1, 'reprise idempotente');

// --- 2. Enregistrement, idempotence, état de règlement
$ref = 'CMD-20261002-BBBBBB';
$order = $db->query("SELECT * FROM \"order\" WHERE order_reference = '{$ref}'")->fetch(PDO::FETCH_ASSOC);
assert(L::orderSettlement($order)['state'] === 'unpaid' && L::orderSettlement($order)['remaining_cents'] === 1200, 'à régler');
$events = [];
$grav->listeners['onSimpleShopPaymentRecorded'][] = function ($e) use (&$events) { $events[] = $e['payment']; };
$r = L::record(['order_reference' => $ref, 'amount_cents' => 500, 'method' => 'bank_transfer', 'paid_at' => '2026-10-05', 'recorded_by' => 'flavien', 'external_ref' => 'VIR-1', 'note' => '1re partie']);
assert($r['created'] && $r['payment']['paid_at'] === '2026-10-05 12:00:00' && $r['payment']['method_label'] === 'Virement' && count($events) === 1);
assert(L::orderSettlement($order)['state'] === 'partial' && L::orderSettlement($order)['remaining_cents'] === 700, 'partiellement réglée');
$again = L::record(['order_reference' => $ref, 'amount_cents' => 500, 'method' => 'bank_transfer', 'external_ref' => 'VIR-1']);
assert(!$again['created'] && $again['payment']['id'] === $r['payment']['id'] && count($events) === 1, 'même référence : pas de doublon');
L::record(['order_reference' => $ref, 'amount_cents' => 700, 'method' => 'check']);
assert(L::orderSettlement($order)['state'] === 'paid', 'réglée');
L::record(['order_reference' => $ref, 'amount_cents' => 100, 'method' => 'cash']);
assert(L::orderSettlement($order)['state'] === 'overpaid', 'trop-perçu');
L::record(['order_reference' => $ref, 'amount_cents' => -1300, 'method' => 'bank_transfer', 'note' => 'remboursement']);
$s = L::orderSettlement($order);
assert($s['state'] === 'refunded' && $s['refunded_cents'] === 1300 && $s['paid_cents'] === 0, 'remboursée : ' . json_encode($s));
foreach ([['amount_cents' => 0], ['method' => 'Pas un identifiant'], ['order_reference' => '']] as $bad) {
    try {
        L::record($bad + ['order_reference' => $ref, 'amount_cents' => 100, 'method' => 'cash']);
        assert(false, 'refusé : ' . json_encode($bad));
    } catch (InvalidArgumentException $e) {
    }
}
assert(L::settle(0, [])['state'] === 'none', 'rien à régler');

// --- 3. Liste et export : état de règlement de chaque commande
$list = \Grav\Plugin\SimpleShop\Orders\OrderManager::search()['items'];
$byRef = array_column($list, 'settlement', 'order_reference');
assert($byRef['CMD-20261001-AAAAAA']['state'] === 'paid' && $byRef[$ref]['state'] === 'refunded');
$csv = \Grav\Plugin\SimpleShop\Orders\OrderManager::exportCsv();
assert(str_contains($csv, 'Règlement;Réglé (€)') && str_contains($csv, 'Remboursée;0,00') && str_contains($csv, 'Réglée;33,89')
    && str_contains($csv, 'FACT-2026-00001 | FACT-2026-00002'), $csv);

echo "OK\n";
