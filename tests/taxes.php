<?php
// Taxes (modèle WooCommerce, CONCEPTION-TAXES.md) : classes et table des taux, résolution par adresse, taxes multiples et
// composées, livraison (taux le plus élevé / prorata / classe fixe), exonération, franchise en base, point d'extension,
// facture d'un panier mixte (XSD EN 16931).
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/taxes.php
require __DIR__ . '/support/bootstrap.php';

use Atgp\FacturX\XsdValidator;
use Grav\Plugin\SimpleShop\Invoicing\FacturXXmlBuilder;
use Grav\Plugin\SimpleShop\Orders\OrderAmounts;
use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Tax\TaxLocation;
use Grav\Plugin\SimpleShop\Tax\TaxResolver;
use Grav\Plugin\SimpleShop\Tax\TaxSettings;

$grav = \SimpleShopTest\boot([], [], null);
$eur = fn (int $c) => Money::of($c, 'EUR');

// 1. Valeurs par défaut : TVA française, classes, exonération
$s = TaxResolver::settings();
assert(array_keys($s['classes']) === ['standard', 'intermediaire', 'reduit', 'particulier', 'zero', 'exonere']);
assert($s['default_class'] === 'standard' && $s['shipping_tax'] === 'items' && $s['based_on'] === 'shipping');
assert(TaxResolver::forClass('reduit')->basisPoints === 550 && TaxResolver::forClass('standard')->basisPoints === 2000);
assert(TaxResolver::forClass('particulier')->basisPoints === 210 && TaxResolver::forClass('intermediaire')->basisPoints === 1000);
$ex = TaxResolver::forClass('exonere');
assert($ex->basisPoints === 0 && $ex->category() === 'E' && $ex->mentions() === ['TVA non applicable']);
assert(TaxResolver::forClass('TVA_5_5')->code === 'reduit', 'ancien code TVA_5_5 → classe reduit');
assert(TaxResolver::forClass('inconnue')->code === 'standard', 'classe inconnue → classe par défaut');
assert(TaxResolver::classFor('book', 'reduit') === 'reduit' && TaxResolver::classFor('autre', null) === 'standard');
assert(TaxResolver::classFor('book', 'reduit', ['tax_class' => 'zero']) === 'zero', 'la fiche l\'emporte');

// 2. Ancienne configuration (taxes.default + rates CODE: {label, rate}) reprise
$legacy = TaxSettings::fromConfig(['taxes' => ['default' => 'TVA_5_5', 'rates' => ['TVA_5_5' => ['label' => 'TVA 5,5 %', 'rate' => 0.055], 'TVA_20' => ['label' => 'TVA 20 %', 'rate' => 0.2]]]]);
assert($legacy['default_class'] === 'TVA_5_5' && count($legacy['rates']) === 2 && $legacy['rates'][0]['bp'] === 550);

// 3. Table des taux : pays, codes postaux, priorités, taxes composées (exemple fictif)
$config = ['taxes' => [
    'shipping_tax' => 'items',
    'classes' => [['code' => 'standard', 'label' => 'Normal'], ['code' => 'livre', 'label' => 'Livres']],
    'rates' => [
        ['class' => 'standard', 'country' => 'FR', 'rate' => 20, 'name' => 'TVA 20 %'],
        ['class' => 'standard', 'country' => 'FR', 'postcodes' => '20*', 'rate' => 13, 'name' => 'TVA Corse 13 %'],
        ['class' => 'standard', 'country' => 'CA', 'region' => 'QC', 'rate' => 5, 'name' => 'TPS', 'priority' => 1],
        ['class' => 'standard', 'country' => 'CA', 'region' => 'QC', 'rate' => 9.975, 'name' => 'TVQ', 'priority' => 2],
        ['class' => 'standard', 'country' => 'XX', 'rate' => 10, 'name' => 'Base', 'priority' => 1],
        ['class' => 'standard', 'country' => 'XX', 'rate' => 5, 'name' => 'Composée', 'priority' => 2, 'compound' => true, 'shipping' => false],
        ['class' => 'livre', 'country' => 'FR', 'rate' => 5.5, 'name' => 'TVA 5,5 %'],
    ],
]];
$at = fn (string $c, string $r = '', string $p = '') => new TaxLocation($c, $r, $p);
assert(TaxResolver::forClass('standard', $at('FR', '', '75002'), $config)->basisPoints === 2000);
assert(TaxResolver::forClass('standard', $at('FR', '', '20000'), $config)->basisPoints === 1300, 'code postal plus précis que le pays');
assert(TaxResolver::forClass('standard', $at('DE'), $config)->components[0]->category === 'O', 'aucune ligne : pas de taxe');
$qc = TaxResolver::forClass('standard', $at('CA', 'QC', 'H2X1Y4'), $config);
assert(count($qc->components) === 2 && $qc->basisPoints === 1498, 'TPS + TVQ = 14,975 % (arrondi en points de base)');
assert($qc->addTo($eur(10000))->amount === 11498 && !$qc->isSimple(), 'deux taxes arrondies chacune : 5,00 + 9,98');
assert($qc->removeFrom($eur(11498))->amount === 10000, 'retour au HT');
$xx = TaxResolver::forClass('standard', $at('XX'), $config);
$b = $xx->breakdown($eur(10000));
assert($b[0]['amount'] === 1000 && $b[1]['amount'] === 550 && $xx->addTo($eur(10000))->amount === 11550, 'composée : 5 % de 110,00');
assert($xx->forShipping()->basisPoints === 1000, 'la composée ne s\'applique pas à la livraison');

// 4. Taxe de la livraison d'un panier mixte (exemple du BOFiP repris par la conception : 40 € à 5,5 %, 60 € à 20 %, port 10 € HT)
$mix = ['livre' => 4000, 'standard' => 6000];
$fr = $at('FR', '', '75002');
$high = TaxResolver::shippingParts($mix, $fr, $config);
assert(count($high) === 1 && $high[0]['rate']->basisPoints === 2000, 'comme WooCommerce : taux le plus élevé');
assert(TaxResolver::shippingTtcFromHt(1000, $high) === 1200);
$config['taxes']['shipping_tax'] = 'items_prorata';
$pro = TaxResolver::shippingParts($mix, $fr, $config);
assert(count($pro) === 2 && TaxResolver::shippingTtcFromHt(1000, $pro) === 1142, 'prorata : 4,22 + 7,20 = 11,42');
$split = TaxResolver::splitShippingTtc(1142, $pro);
assert(array_sum(array_column($split, 'ttc')) === 1142 && array_sum(array_column($split, 'ht')) === 1000, json_encode($split));
$config['taxes']['shipping_tax'] = 'livre';
$fixed = TaxResolver::shippingParts($mix, $fr, $config);
assert(count($fixed) === 1 && $fixed[0]['rate']->basisPoints === 550, 'classe fixe');
assert(TaxResolver::splitHt(1000, [['rate' => null, 'weight' => 1], ['rate' => null, 'weight' => 2]]) === [['rate' => null, 'ht' => 333], ['rate' => null, 'ht' => 667]], 'centimes exacts');

// 5. Franchise en base et taxes désactivées
$sb = TaxResolver::forClass('standard', $fr, ['taxes' => ['small_business' => ['enabled' => true]]]);
assert($sb->basisPoints === 0 && $sb->category() === 'E' && $sb->mentions() === ['TVA non applicable, art. 293 B du CGI']);
assert(TaxResolver::forClass('standard', $fr, ['taxes' => ['enabled' => false]])->basisPoints === 0);

// 6. Adresse qui décide : celle de livraison du client dès qu'elle est connue
$grav['session']->shop_order = (object) ['customer' => ['postal-code' => '20000']];
$corse = ['taxes' => $config['taxes']];
assert(TaxResolver::forClass('standard', null, $corse)->basisPoints === 1300, 'adresse de livraison du client');
$corse['taxes']['based_on'] = 'shop';
assert(TaxResolver::forClass('standard', null, $corse)->basisPoints === 2000, 'adresse de la boutique');
$grav['session']->shop_order = new stdClass();

// 7. Point d'extension : un plugin modifie la table des taux
TaxResolver::reset();
$grav->listeners['onSimpleShopTaxRates'][] = function ($event) {
    $rates = $event['rates'];
    $rates[] = ['class' => 'reduit', 'country' => 'FR', 'postcodes' => '97*', 'rate' => 2.1, 'name' => 'TVA DOM 2,1 %'];
    $event['rates'] = $rates;
};
$grav['session']->shop_order = (object) ['customer' => ['postal-code' => '97100']];
assert(TaxResolver::forClass('reduit')->basisPoints === 210, 'taux ajouté par un plugin');
$grav['session']->shop_order = new stdClass();
TaxResolver::reset();
$grav->listeners['onSimpleShopTaxRates'] = [];

// 8. Facture d'un panier mixte avec exonération : un bloc par taxe, motif d'exonération, XSD EN 16931
$reduit = TaxResolver::forClass('reduit');
$normal = TaxResolver::forClass('standard');
$exo = TaxResolver::forClass('exonere');
$item = fn (string $title, $rate, int $ht, int $qty) => ['title' => $title, 'quantity' => $qty, 'unit_ht_cents' => $ht, 'unit_ttc_cents' => $rate->addTo($eur($ht))->amount,
    'tax_code' => $rate->code, 'tax_bp' => $rate->basisPoints, 'tax_components' => array_map(fn ($c) => $c->jsonSerialize(), $rate->components), 'route' => '/' . $title];
$cart = ['a' => $item('livre', $reduit, 4000, 1), 'b' => $item('objet', $normal, 6000, 1), 'c' => $item('formation', $exo, 20000, 1) + ['shippable' => false]];
assert(OrderAmounts::taxMix($cart) === ['reduit' => 4000, 'standard' => 6000], 'la formation (non expédiée) ne compte pas');
$amounts = OrderAmounts::fromCart($cart, ['name' => 'Colissimo', 'price_cents' => 1142], [['rate' => $reduit, 'weight' => 4000], ['rate' => $normal, 'weight' => 6000]], 'EUR');
assert(count($amounts['shipping_lines']) === 2 && $amounts['shipping_ttc_cents'] === 1142 && $amounts['shipping_ht_cents'] === 1000);
assert($amounts['total_ttc_cents'] === 4220 + 7200 + 20000 + 1142);
$totals = OrderAmounts::invoiceTotals($amounts);
assert($totals['en16931'] && count($totals['taxes']) === 3 && $totals['mentions'] === ['TVA non applicable']);
$byCat = array_column($totals['taxes'], null, 'name');
assert($byCat['TVA 5,5 %']['base_cents'] === 4400 && $byCat['TVA 20 %']['base_cents'] === 6600 && $byCat['Exonéré']['tax_cents'] === 0, json_encode($totals['taxes']));
$seller = ['name' => 'Éditions Test', 'postal_code' => '75001', 'city' => 'Paris', 'country' => 'FR', 'siret' => '12345678900011', 'vat_number' => 'FR32123456789'];
$order = ['order_reference' => 'CMD-T', 'created_at' => '2026-10-15 10:00:00', 'customer_data' => '{"firstname":"A","lastname":"B","city":"Paris","postal-code":"75002"}',
    'billing_data' => '{}', 'shipping_data' => '{"name":"Colissimo"}', 'currency' => 'EUR'];
$xml = (new FacturXXmlBuilder($order, ['invoice_number' => 'FACT-T'], $seller, $amounts, $totals))->build();
$v = new XsdValidator();
assert($v->validate($xml), 'XSD : ' . implode(' | ', $v->getErrors()));
assert(str_contains($xml, '<ram:ExemptionReason>TVA non applicable</ram:ExemptionReason>') && str_contains($xml, '<ram:CategoryCode>E</ram:CategoryCode>'));

// 9. Commande enregistrée relue à l'identique (livraison en deux parts)
$stored = $order + ['total_cents' => $amounts['total_ttc_cents'], 'shipping_taxes' => json_encode(array_map(fn ($l) => ['title' => $l['title'], 'ht' => $l['unit_ht_cents'],
    'ttc' => $l['unit_ttc_cents'], 'code' => $l['tax_code'], 'bp' => $l['tax_bp'], 'taxes' => $l['taxes']], $amounts['shipping_lines']))];
$rows = array_map(fn ($l) => ['title' => $l['title'], 'quantity' => $l['quantity'], 'unit_ht_cents' => $l['unit_ht_cents'], 'unit_ttc_cents' => $l['unit_ttc_cents'],
    'tax_code' => $l['tax_code'], 'tax_bp' => $l['tax_bp'], 'taxes' => json_encode($l['taxes'])], array_filter($amounts['lines'], fn ($l) => $l['kind'] === 'item'));
$again = OrderAmounts::fromStored($stored, array_values($rows), $reduit);
assert($again['total_ttc_cents'] === $amounts['total_ttc_cents'] && OrderAmounts::invoiceTotals($again)['taxes'] == $totals['taxes'], 'relecture identique');

// 10. Plusieurs taxes sur une ligne : facture sans XML EN 16931 (signalé)
$multi = OrderAmounts::fromCart(['q' => $item('livre-qc', $qc, 10000, 1)], [], [['rate' => $qc, 'weight' => 1]], 'EUR');
assert(OrderAmounts::invoiceTotals($multi)['en16931'] === false && OrderAmounts::invoiceTotals($multi)['tax_total_cents'] === 1498);

echo "OK\n";
