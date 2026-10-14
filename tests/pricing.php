<?php
// Tests en ligne de commande des montants (Money), taux (TaxRate) et prix (Price) en entiers.
// Lancement : php -d zend.assertions=1 -d assert.exception=1 tests/pricing.php
spl_autoload_register(function ($c) {
    $p = 'Grav\\Plugin\\SimpleShop\\';
    if (strpos($c, $p) === 0) {
        $f = __DIR__ . '/../classes/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

use Grav\Plugin\SimpleShop\Pricing\{Money, Price, TaxRate};

$fails = function (callable $f): bool { try { $f(); } catch (\InvalidArgumentException|\LogicException $e) { return true; } return false; };

// --- Saisie (2 décimales max, point ou virgule)
assert(Money::parse('12.95', 'EUR')->amount === 1295);
assert(Money::parse('12,9', 'eur')->amount === 1290 && Money::parse('12,9', 'eur')->currency === 'EUR');
assert(Money::parse('7', 'EUR')->amount === 700 && Money::parse('0.01', 'EUR')->amount === 1);
foreach (['12.999', '-1', 'abc', '', '1.2.3', '12.'] as $bad) {
    assert($fails(fn () => Money::parse($bad, 'EUR')), "saisie refusée : {$bad}");
}
assert($fails(fn () => Money::of(1, 'EURO')));
assert(Money::of(1295, 'EUR')->toDecimalString() === '12.95' && Money::of(5, 'EUR')->toDecimalString() === '0.05');

// --- Anciennes valeurs à 4 décimales (fiches HT actuelles)
assert(Money::fromLegacyDecimal('12.2749', 'EUR')->amount === 1227);
assert(Money::fromLegacyDecimal(14.218, 'EUR')->amount === 1422);
assert(Money::fromLegacyDecimal('0.005', 'EUR')->amount === 1);
assert(Money::fromLegacyDecimal(0.1 + 0.2, 'EUR')->amount === 30, 'pas d’erreur binaire');

// --- Opérations
$a = Money::of(1295, 'EUR');
assert($a->add(Money::of(799, 'EUR'))->amount === 2094 && $a->multiply(3)->amount === 3885);
assert($fails(fn () => $a->add(Money::of(1, 'USD'))), 'devises différentes refusées');

// --- Taux
$tva55 = TaxRate::fromDecimal('TVA_5_5', 0.055);
assert($tva55->basisPoints === 550 && TaxRate::fromDecimal('TVA_20', 20)->basisPoints === 2000);
assert($fails(fn () => new TaxRate('X', 10000)));

// --- Prix saisi HT ou TTC
$fromHt = Price::fromEntered(Money::parse('12.27', 'EUR'), $tva55, Price::HT);
assert($fromHt->unitTtc->amount === 1294, '12,27 × 1,055 = 12,94485 → 12,94');
$fromTtc = Price::fromEntered(Money::parse('12.95', 'EUR'), $tva55, Price::TTC);
assert($fromTtc->unitTtc->amount === 1295 && $fromTtc->unitHt->amount === 1227, '12,95 / 1,055 = 12,2748… → 12,27');
assert($fromTtc->entered()->amount === 1295 && $fromHt->entered()->amount === 1227);
assert($fromTtc->line(3)['ttc']->amount === 3885 && $fromTtc->line(3)['ht']->amount === 3681);
// Arrondi demi supérieur exact (pas d'artifice 1e-9) : 10,00 HT à 5,5 % = 10,55 ; 0,10 HT = 0,1055 → 0,11.
assert(Price::fromEntered(Money::of(1000, 'EUR'), $tva55, Price::HT)->unitTtc->amount === 1055);
assert(Price::fromEntered(Money::of(10, 'EUR'), $tva55, Price::HT)->unitTtc->amount === 11);
assert($tva55->taxOn(Money::of(3681, 'EUR'))->amount === 202, '36,81 × 5,5 % = 2,02455 → 2,02');
assert($fails(fn () => Price::fromEntered(Money::of(-1, 'EUR'), $tva55, Price::HT)));
assert($fails(fn () => Price::fromEntered(Money::of(1, 'EUR'), $tva55, 'brut')));

// --- Instantané (ligne de commande)
$snap = json_decode(json_encode($fromTtc), true);
assert($snap === ['unit_ht_cents' => 1227, 'unit_ttc_cents' => 1295, 'currency' => 'EUR', 'tax_code' => 'TVA_5_5', 'tax_bp' => 550, 'entered_as' => 'ttc']);
$back = Price::fromArray($snap);
assert($back->unitTtc->equals($fromTtc->unitTtc) && $back->tax->code === 'TVA_5_5' && $back->enteredAs === Price::TTC);

// --- Tous les prix TTC ronds de 0,01 à 200,00 € sont retrouvés exactement depuis leur HT dérivé ? (non garanti : on le mesure)
$lost = 0;
for ($c = 1; $c <= 20000; $c++) {
    $p = Price::fromEntered(Money::of($c, 'EUR'), $tva55, Price::TTC);
    if ($tva55->addTo($p->unitHt)->amount !== $c) {
        $lost++;
    }
}
echo "Prix TTC non retrouvables depuis un HT au centime (5,5 %) : {$lost} / 20000 — d'où la saisie possible en TTC.\n";

echo "OK\n";
