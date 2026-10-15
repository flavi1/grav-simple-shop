<?php
// Facture Factur-X (profil EN 16931) : montants en centimes figés par ligne, plusieurs taux, arrondi BT-114,
// relecture des anciennes commandes, validation XSD officielle et règles de calcul EN 16931 (BR-CO-10, 13, 14, 15, 16).
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/invoice.php
require __DIR__ . '/support/bootstrap.php';

use Atgp\FacturX\XsdValidator;
use Grav\Plugin\SimpleShop\Invoicing\FacturXXmlBuilder;
use Grav\Plugin\SimpleShop\Invoicing\Invoice;
use Grav\Plugin\SimpleShop\Orders\OrderAmounts;
use Grav\Plugin\SimpleShop\Pricing\TaxRate;

$grav = \SimpleShopTest\boot([], [], null);   // configuration du plugin (taxe du site) pour Invoice::build()
$site = new TaxRate('TVA_5_5', 550);
$seller = ['name' => 'Éditions Test', 'address_line1' => '1 rue du Livre', 'postal_code' => '75001', 'city' => 'Paris',
    'country' => 'FR', 'siret' => '12345678900011', 'vat_number' => 'FR00123456789', 'billing_mode' => 'B2',
    'notes' => ['AAB' => "Pas d'escompte.", 'PMD' => 'Pas de pénalités.', 'PMT' => "Pas d'indemnité."]];
$customer = json_encode(['firstname' => 'Marie', 'lastname' => 'Curie', 'street' => '1 rue de la Paix', 'postal-code' => '75002', 'city' => 'Paris']);

/** Vérifie le XML : XSD officiel + règles de calcul EN 16931. Retourne les valeurs lues. */
$check = function (string $xml, int $paidCents): array {
    $v = new XsdValidator();
    assert($v->validate($xml), 'XSD EN 16931 : ' . implode(' | ', $v->getErrors()));
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $c = fn (string $q): int => (int) round(((float) $xp->evaluate("string($q)")) * 100);
    $sum = 0;
    foreach ($xp->query('//ram:IncludedSupplyChainTradeLineItem') as $li) {
        $net = (int) round((float) $xp->evaluate('string(.//ram:NetPriceProductTradePrice/ram:ChargeAmount)', $li) * 100);
        $qty = (int) $xp->evaluate('string(.//ram:BilledQuantity)', $li);
        $lineTotal = (int) round((float) $xp->evaluate('string(.//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount)', $li) * 100);
        assert($lineTotal === $net * $qty, "BT-131 = BT-146 × BT-129 ({$lineTotal} ≠ {$net} × {$qty})");
        $sum += $lineTotal;
    }
    $h = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
    $lineTotal = $c($h . 'ram:LineTotalAmount');
    $taxTotal = $c($h . 'ram:TaxTotalAmount');
    $grand = $c($h . 'ram:GrandTotalAmount');
    $rounding = $c($h . 'ram:RoundingAmount');
    $prepaid = $c($h . 'ram:TotalPrepaidAmount');
    $due = $c($h . 'ram:DuePayableAmount');
    assert($sum === $lineTotal, 'BR-CO-10 : somme des lignes = BT-106');
    $catTax = 0;
    $catBase = 0;
    foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $t) {
        $base = (int) round((float) $xp->evaluate('string(ram:BasisAmount)', $t) * 100);
        $amt = (int) round((float) $xp->evaluate('string(ram:CalculatedAmount)', $t) * 100);
        $rate = (float) $xp->evaluate('string(ram:RateApplicablePercent)', $t);
        assert($amt === (int) round($base * $rate / 100), 'BR-S-09 : TVA = base × taux');
        assert($xp->evaluate('string(ram:CategoryCode)', $t) === 'S', 'catégorie S');
        $catTax += $amt;
        $catBase += $base;
    }
    assert($catBase === $lineTotal && $catTax === $taxTotal, 'BR-CO-14 : TVA totale = somme des catégories');
    assert($grand === $lineTotal + $taxTotal, 'BR-CO-15 : BT-112 = BT-109 + BT-110');
    assert($due === $grand - $prepaid + $rounding && $due === 0, 'BR-CO-16 : BT-115 = BT-112 − BT-113 + BT-114 = 0');
    assert($prepaid === $paidCents, 'déjà payé = montant encaissé');
    assert($xp->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)') === FacturXXmlBuilder::PROFILE_URN, 'profil EN 16931');

    return ['grand' => $grand, 'rounding' => $rounding, 'tax' => $taxTotal, 'lines' => $lineTotal];
};

// 1. Commande en centimes : livre à 5,5 % (12,95 TTC = 12,27 HT), objet à 20 % (saisi TTC 10,00 = 8,33 HT), livraison 7,99 TTC.
$order = ['order_reference' => 'CMD-1', 'created_at' => '2026-10-14 10:00:00', 'customer_data' => $customer, 'billing_data' => '{}',
    'shipping_data' => json_encode(['name' => 'Colissimo', 'price' => 7.99]), 'currency' => 'EUR', 'total_cents' => 1295 * 2 + 1000 + 799,
    'shipping_ht_cents' => 757, 'shipping_ttc_cents' => 799, 'shipping_tax_code' => 'TVA_5_5', 'shipping_tax_bp' => 550];
$items = [
    ['product_route' => '/livres/a', 'title' => 'Un livre', 'quantity' => 2, 'price' => 12.95, 'unit_ht_cents' => 1227, 'unit_ttc_cents' => 1295, 'tax_code' => 'TVA_5_5', 'tax_bp' => 550],
    ['product_route' => '/objets/b', 'title' => 'Un objet', 'quantity' => 1, 'price' => 10.0, 'unit_ht_cents' => 833, 'unit_ttc_cents' => 1000, 'tax_code' => 'TVA_20', 'tax_bp' => 2000],
];
$amounts = OrderAmounts::fromStored($order, $items, $site, 0.2);   // le taux de repli ne sert pas : les lignes ont leur taux
assert($amounts['total_ttc_cents'] === 4389, 'total payé = somme des TTC');
$totals = OrderAmounts::invoiceTotals($amounts);
assert(count($totals['taxes']) === 2, 'un bloc TVA par taux');
[$t55, $t20] = $totals['taxes'][0]['bp'] === 550 ? $totals['taxes'] : array_reverse($totals['taxes']);
assert($t55['base_cents'] === 1227 * 2 + 757 && $t55['tax_cents'] === 177, '5,5 % : base 32,11, TVA 1,77 (32,11 × 5,5 % = 1,766)');
assert($t20['base_cents'] === 833 && $t20['tax_cents'] === 167, '20 % : base 8,33, TVA 1,67');
assert($totals['grand_total_cents'] === 3211 + 833 + 177 + 167 && $totals['rounding_cents'] === 4389 - 4388, 'arrondi +0,01 (BT-114)');
$xml = (new FacturXXmlBuilder($order, ['invoice_number' => 'FACT-2026-00001'], $seller, $amounts, $totals))->build();
$r = $check($xml, 4389);
assert($r['rounding'] === 1, 'RoundingAmount présent');
assert(strpos($xml, '<ram:ChargeAmount>12.27</ram:ChargeAmount>') !== false, 'prix net HT (et non TTC) sur la ligne');
// Règles françaises (testeur FNFE, BR-FR) : cadre de facturation, mentions, SIREN, adresses électroniques, livré à
assert(str_contains($xml, '<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>B2</ram:ID>'), 'BT-23 = B2');
assert(str_contains($xml, '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">20261014</udt:DateTimeString>'), 'BT-9 = date du paiement (BR-FR-CO-09)');
foreach (['AAB', 'PMD', 'PMT'] as $code) {
    assert(str_contains($xml, '<ram:SubjectCode>' . $code . '</ram:SubjectCode>'), "mention {$code}");
}
assert(str_contains($xml, '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">123456789</ram:ID>'), 'BT-30 = SIREN (9 chiffres)');
assert(str_contains($xml, '<ram:URIID schemeID="0225">123456789</ram:URIID>'), 'BT-34 = SIREN, schéma 0225');
assert(str_contains($xml, '<ram:ShipToTradeParty><ram:Name>Marie Curie</ram:Name>'), 'livré à (BG-13)');
assert(!preg_match('~<ram:([A-Za-z]+)(?: [^>]*)?/>|<ram:([A-Za-z]+)></ram:\2>~', $xml), 'aucun élément vide (PEPPOL-EN16931-R008)');

// 2. Sans écart d'arrondi : pas de RoundingAmount
$order2 = ['shipping_ttc_cents' => 0, 'shipping_ht_cents' => 0, 'shipping_tax_code' => 'TVA_5_5', 'shipping_tax_bp' => 550, 'currency' => 'EUR'] + $order;
$items2 = [['title' => 'Livre', 'quantity' => 1, 'unit_ht_cents' => 1000, 'unit_ttc_cents' => 1055, 'tax_code' => 'TVA_5_5', 'tax_bp' => 550]];
$a2 = OrderAmounts::fromStored($order2, $items2, $site);
$t2 = OrderAmounts::invoiceTotals($a2);
assert($t2['rounding_cents'] === 0 && count($a2['lines']) === 1, 'livraison gratuite : pas de ligne de livraison, pas d’arrondi');
$xml2 = (new FacturXXmlBuilder($order2, ['invoice_number' => 'FACT-2026-00002'], $seller, $a2, $t2))->build();
$check($xml2, 1055);
assert(strpos($xml2, 'RoundingAmount') === false);

// 3. Ancienne commande (avant les centimes) : prix décimal TTC et taux figé sur la facture
$legacy = ['order_reference' => 'CMD-OLD', 'created_at' => '2026-09-01 10:00:00', 'customer_data' => $customer, 'billing_data' => '{}',
    'shipping_data' => json_encode(['name' => 'Lettre suivie', 'price' => 3.0]), 'total_amount' => 28.9];
$legacyItems = [['product_route' => '/livres/a', 'title' => 'Un livre', 'quantity' => 2, 'price' => 12.95]];
$a3 = OrderAmounts::fromStored($legacy, $legacyItems, $site, 0.055);
assert($a3['total_ttc_cents'] === 2890 && $a3['currency'] === 'EUR', 'ancienne commande : total identique à total_amount');
assert($a3['lines'][0]['unit_ht_cents'] === 1227 && $a3['lines'][1]['unit_ht_cents'] === 284, 'HT dérivés du TTC au taux figé');
$check((new FacturXXmlBuilder($legacy, ['invoice_number' => 'FACT-2026-00003'], $seller, $a3, OrderAmounts::invoiceTotals($a3)))->build(), 2890);

// 4. PDF Factur-X complet (FPDF + XML embarqué)
$pdf = (new Invoice($order, $items, ['invoice_number' => 'FACT-2026-00001', 'vat_rate' => 0.055], $seller))->build();
assert(strncmp($pdf, '%PDF', 4) === 0 && strpos($pdf, 'factur-x.xml') !== false, 'PDF Factur-X avec XML embarqué');
// PDF/A-3 : toutes les polices embarquées (ISO 19005-3, 6.2.11.4.1) — vérifié avec pdffonts si disponible
$tmpPdf = sys_get_temp_dir() . '/facture-test-' . getmypid() . '.pdf';
file_put_contents($tmpPdf, $pdf);
exec('pdffonts ' . escapeshellarg($tmpPdf) . ' 2>/dev/null', $fonts, $rc);
if ($rc === 0) {
    $rows = array_slice($fonts, 2);
    assert($rows !== [], 'polices listées');
    foreach ($rows as $row) {
        assert(preg_match('/\byes\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+\s*$/', $row) === 1 && !str_contains($row, 'Helvetica'), 'police non embarquée : ' . $row);
    }
}
@unlink($tmpPdf);

// 5. Panier : mêmes montants que la commande
$cart = ['/livres/a' => ['title' => 'Un livre', 'quantity' => 2, 'price' => 12.95, 'unit_ht_cents' => 1227, 'unit_ttc_cents' => 1295, 'tax_code' => 'TVA_5_5', 'tax_bp' => 550]];
$ac = OrderAmounts::fromCart($cart, ['name' => 'Colissimo', 'price' => 7.99, 'price_cents' => 799], [['rate' => $site, 'weight' => 1]], 'EUR');
assert($ac['total_ttc_cents'] === 3389 && $ac['shipping']['unit_ht_cents'] === 757 && $ac['items_ht_cents'] === 2454, 'panier en centimes');
$ac = OrderAmounts::fromCart($cart, ['price' => 7.99], [['rate' => $site, 'weight' => 1]], 'EUR');   // ancienne session : prix décimal seul
assert($ac['total_ttc_cents'] === 3389, 'ancienne session de livraison relue');

// 6. Migration additive d'une base existante (schéma d'avant les centimes) : colonnes ajoutées, données intactes, rejouable
$dbPath = $grav['locator']->findResource('user://data/simple-shop/shop.db', true, true);
@mkdir(dirname($dbPath), 0777, true);
@unlink($dbPath);
@unlink(dirname($dbPath) . '/.shop-schema-applied');
$old = new PDO('sqlite:' . $dbPath);
$old->exec(file_get_contents(__DIR__ . '/../shop.sql'));
$old->exec("INSERT INTO \"order\" (order_reference, customer_data, shipping_data, billing_data, total_amount) VALUES ('CMD-OLD', '{}', '{}', '{}', 28.9)");
$old = null;
$db = \Grav\Plugin\SimpleShop\Inventory\InventoryManager::getDatabase();
$cols = array_column($db->query('PRAGMA table_info("order_item")')->fetchAll(PDO::FETCH_ASSOC), 'name');
assert(in_array('unit_ttc_cents', $cols, true) && in_array('tax_bp', $cols, true), 'colonnes ajoutées à order_item');
$row = $db->query('SELECT total_amount, total_cents FROM "order"')->fetch(PDO::FETCH_ASSOC);
assert((float) $row['total_amount'] === 28.9 && $row['total_cents'] === null, 'ancienne commande intacte, centimes vides');
$db = null;
// Base déjà migrée avec un ancien marqueur (date seule, avant les colonnes) : la migration doit se rejouer.
file_put_contents(dirname($dbPath) . '/.shop-schema-applied', (string) filemtime(__DIR__ . '/../shop.sql'));
assert(strpos(file_get_contents(dirname($dbPath) . '/.shop-schema-applied'), '-') === false);
\Grav\Plugin\SimpleShop\Inventory\InventoryManager::getDatabase();   // migration rejouée sans erreur
assert(strpos(file_get_contents(dirname($dbPath) . '/.shop-schema-applied'), '-') !== false, 'nouveau marqueur (date + colonnes)');
assert(!array_filter($grav['log']->lines, fn ($l) => $l[0] === 'error'), 'aucune erreur de migration');

echo "OK\n";
