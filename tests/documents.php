<?php
// Documents saisis dans l'administration : commande manuelle (brouillon), facture d'acompte, facture finale (acomptes
// déduits), demande de paiement puis facture au règlement, état de règlement, Factur-X « à régler » (XSD EN 16931),
// copie acquittée (même XML), franchise en base.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/documents.php
require __DIR__ . '/support/bootstrap.php';

use Atgp\FacturX\XsdValidator;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager as D;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger as L;

$grav = \SimpleShopTest\boot([
    'invoicing' => ['manual' => true, 'payment_terms_days' => 30, 'operation_nature' => 'services'],
    'seller' => ['name' => 'Flavien G', 'address_line1' => '1 rue du Code', 'postal_code' => '75011', 'city' => 'Paris', 'country' => 'FR',
        'siret' => '12345678900011', 'vat_number' => 'FR00123456789'],
    'payments' => ['bank_transfer' => ['enabled' => true, 'holder' => 'Flavien G', 'iban' => 'FR76 3000 6000 0112 3456 7890 189', 'bic' => 'AGRIFRPP'],
        'methods' => ['manual' => []]],
], [], null);
$grav['config']->set('site.title', 'Mon site');
// Passerelles : virement (cœur, ce qu'enregistre SimpleShopPlugin::registerCoreGateways()) + test (bootstrap).
$grav->listeners['onSimpleShopRegisterGateways'][] = function ($event) {
    $g = $event['gateways'] ?? [];
    $g['bank_transfer'] = new \Grav\Plugin\SimpleShop\Gateways\BankTransferGateway();
    $event['gateways'] = $g;
};

$xsd = function (string $xml): DOMXPath {
    $v = new XsdValidator();
    assert($v->validate($xml), 'XSD EN 16931 : ' . implode(' | ', $v->getErrors()));
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    return $xp;
};
$money = fn (DOMXPath $xp, string $tag): int => (int) round(((float) $xp->evaluate("string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:{$tag})")) * 100);
$dir = $grav['locator']->findResource('user://data/simple-shop/invoices', true, true);

// --- 1. Brouillon : validation, client professionnel, prix HT
$bad = D::saveDraft(['customer' => ['type' => 'b2b'], 'lines' => []], 'flavien');
assert($bad['reference'] === null && isset($bad['errors']['customer.email'], $bad['errors']['customer.company'], $bad['errors']['lines']), json_encode($bad));
$input = [
    'customer' => ['type' => 'b2b', 'email' => 'client@exemple.fr', 'company' => 'ACME SAS', 'siren' => '732 829 320', 'vat' => 'FR40732829320',
        'firstname' => 'Ada', 'lastname' => 'Lovelace', 'street' => '10 rue des Lilas', 'postal-code' => '69001', 'city' => 'Lyon'],
    'lines' => [
        ['title' => 'Création du site', 'quantity' => 1, 'unit_price' => '2000.00', 'tax_type' => 'Taux normal'],
        ['title' => 'Hébergement (an)', 'quantity' => 2, 'unit_price' => '99,99', 'tax_type' => 'Taux normal'],
        ['title' => '', 'unit_price' => ''],
    ],
    'comment' => 'Merci pour votre confiance.',
];
$r = D::saveDraft($input, 'flavien');
assert($r['errors'] === [] && preg_match('/^CMD-\d{8}-[A-F0-9]{6}$/', $r['reference']), json_encode($r));
$ref = $r['reference'];
$order = \Grav\Plugin\SimpleShop\Orders\OrderManager::getOrder($ref);
assert($order['status'] === 'draft' && $order['source'] === 'manual' && (int) $order['total_cents'] === 263998, 'brouillon : 2 199,98 HT + TVA 20 % (440,00) = 2 639,98 : ' . $order['total_cents']);

$items = D::orderItems($ref);
assert(count($items) === 2 && (int) $items[1]['unit_ht_cents'] === 9999 && (int) $items[1]['unit_ttc_cents'] === 11999 && $items[1]['tax_code'] === 'taux-normal');
$in = D::draftInput($order, $items);
assert($in['lines'][1]['unit_price'] === '99.99' && $in['price_basis'] === 'ht' && $in['customer']['company'] === 'ACME SAS', 'brouillon rouvert');
$m = $in['methods']; sort($m); assert($m === ['bank_transfer', 'test_gateway'], 'moyens par défaut : tous ceux disponibles pour les documents');
// Modification du brouillon tant que rien n'est émis
$input['lines'][1]['quantity'] = 1;
assert(D::saveDraft($input, 'flavien', $ref)['errors'] === []);
assert((int) \Grav\Plugin\SimpleShop\Orders\OrderManager::getOrder($ref)['total_cents'] === 251999, '2 099,99 HT → 2 519,99 TTC');

// --- 2. Facture d'acompte 30 %
$bad = D::issue($ref, 'deposit', ['deposit' => ['mode' => 'percent', 'value' => '150']]);
assert(isset($bad['errors']['deposit']));
$dep = D::issue($ref, 'deposit', ['deposit' => ['mode' => 'percent', 'value' => '30'], 'issued_by' => 'flavien'])['document'];
assert($dep['kind'] === 'deposit' && preg_match('/^FACT-\d{4}-00001$/', $dep['invoice_number']) && $dep['total_cents'] === 75600 && $dep['due_cents'] === 75600,
    'acompte : 30 % de 2 099,99 HT = 630,00 HT → 756,00 TTC : ' . json_encode([$dep['total_cents'], $dep['due_cents']]));
assert(preg_match('/^[a-f0-9]{32}$/', $dep['access_token']) && $dep['customer_type'] === 'b2b' && $dep['ereporting_status'] === 'not_submitted');
assert(\Grav\Plugin\SimpleShop\Orders\OrderManager::getOrder($ref)['status'] === 'pending', 'brouillon → en attente à la première émission');
assert(D::saveDraft($input, 'flavien', $ref)['errors'] !== [], 'plus modifiable après émission');
$xp = $xsd(file_get_contents($dir . '/' . $dep['invoice_number'] . '.xml'));
assert($xp->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)') === '386', 'type 386 (acompte)');
assert($xp->evaluate('string(//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID)') === 'S1', 'cadre S1 (services, à régler)');
assert($money($xp, 'DuePayableAmount') === 75600 && $xp->evaluate('count(//ram:TotalPrepaidAmount)') == 0, 'reste dû = total');
assert($xp->evaluate('string(//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode)') === '30'
    && $xp->evaluate('string(//ram:IBANID)') === 'FR7630006000011234567890189' && $xp->evaluate('string(//ram:BICID)') === 'AGRIFRPP', 'virement : IBAN, BIC');
$due = (new DateTime('+30 days', new DateTimeZone('UTC')))->format('Ymd');
assert($xp->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime)') === $due && str_contains($xp->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)'), 'À régler'), 'échéance J+30');
assert($xp->evaluate('string(//ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID)') === '732829320' && $xp->evaluate('string(//ram:BuyerTradeParty//ram:URIID/@schemeID)') === '0225', 'acheteur : SIREN');
assert($xp->evaluate('string(//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)') === 'FR40732829320');
assert(str_contains($xp->evaluate('string(//rsm:ExchangedDocument)'), '40 €') && str_contains($xp->evaluate('string(//rsm:ExchangedDocument)'), "trois fois le taux"), 'mentions B2B');
$st = D::settlement($dep);
assert($st['state'] === 'unpaid' && $st['remaining_cents'] === 75600);
// Paiement partiel puis total de l'acompte (virement saisi à la main)
L::record(['order_reference' => $ref, 'invoice_id' => $dep['id'], 'amount_cents' => 50000, 'method' => 'bank_transfer']);
assert(D::settlement($dep)['state'] === 'partial');
L::record(['order_reference' => $ref, 'invoice_id' => $dep['id'], 'amount_cents' => 25600, 'method' => 'bank_transfer']);
assert(D::settlement($dep)['state'] === 'paid');
$paidCopy = D::pdfPath($dep, 'paid');
assert($paidCopy && is_file($paidCopy) && str_contains((string) file_get_contents($paidCopy), 'factur-x.xml'), 'copie acquittée Factur-X');
// Le XML embarqué dans la copie acquittée est exactement celui de l'original (atgp/factur-x Reader)
$reader = new \Atgp\FacturX\Reader();
assert($reader->extractXML(file_get_contents($paidCopy)) === file_get_contents($dir . '/' . $dep['invoice_number'] . '.xml'), 'même XML');
$issued = D::issue($ref, 'payment_request'); assert($issued['errors'] === [] && D::pdfPath($issued['document'], 'paid') === null, 'DP émise ; pas de copie acquittée tant qu’elle n’est pas réglée');

// --- 3. Demande de paiement du solde (sans valeur fiscale), puis facture finale
$docs = D::documentsForOrder($ref);
$dp = end($docs);
assert($dp['kind'] === 'payment_request' && preg_match('/^DP-\d{4}-00001$/', $dp['invoice_number']) && $dp['due_cents'] === 251999 - 75600, 'DP du reste');
assert(!is_file($dir . '/' . $dp['invoice_number'] . '.xml') && is_file($dir . '/' . $dp['invoice_number'] . '.pdf'), 'DP : PDF simple, sans Factur-X');
assert($dp['ereporting_status'] === 'not_applicable');
assert(isset(D::issue($ref, 'payment_request')['errors']['kind']), 'une seule DP en attente');
// Paiement de la DP par carte (référence de passerelle) → facture émise automatiquement, réglée
$pay = L::record(['order_reference' => $ref, 'invoice_id' => $dp['id'], 'amount_cents' => $dp['due_cents'], 'method' => 'test_gateway', 'external_ref' => 'test-1']);
$inv = D::afterPayment($dp['id']);
assert($inv !== null && $inv['kind'] === 'invoice' && $inv['source_invoice_id'] === $dp['id'] && preg_match('/^FACT-\d{4}-00002$/', $inv['invoice_number']));
assert(D::afterPayment($dp['id']) === null, 'une seule facture');
assert(D::settlement($inv)['state'] === 'paid', 'facture réglée (paiement de la DP)');
$xp = $xsd(file_get_contents($dir . '/' . $inv['invoice_number'] . '.xml'));
assert($xp->evaluate('string(//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID)') === 'S2' && $money($xp, 'DuePayableAmount') === 0, 'facture déjà payée : S2, reste 0');
$o = \Grav\Plugin\SimpleShop\Orders\OrderManager::getOrder($ref);
assert(L::orderSettlement($o)['state'] === 'paid', 'commande réglée (acompte + solde)');

// --- 4. Facture finale après acomptes (autre commande, particulier, prix TTC)
$r2 = D::saveDraft(['customer' => ['type' => 'b2c', 'email' => 'marie@exemple.fr', 'firstname' => 'Marie', 'lastname' => 'Curie', 'street' => '1 rue',
    'postal-code' => '75005', 'city' => 'Paris'], 'lines' => [['title' => 'Atelier', 'quantity' => 1, 'unit_price' => '120.00', 'tax_type' => 'Taux normal']],
    'methods' => ['test_gateway', 'inconnu']], 'flavien');
$ref2 = $r2['reference'];
$o2 = \Grav\Plugin\SimpleShop\Orders\OrderManager::getOrder($ref2);
assert((int) $o2['total_cents'] === 12000 && json_decode($o2['payload'], true)['methods'] === ['test_gateway'] && json_decode($o2['payload'], true)['price_basis'] === 'ttc');
$d2 = D::issue($ref2, 'deposit', ['deposit' => ['mode' => 'amount', 'value' => '50']])['document'];
assert($d2['total_cents'] === 5000, 'acompte 50 € TTC');
$f2 = D::issue($ref2, 'invoice')['document'];
assert($f2['total_cents'] === 12000 && $f2['due_cents'] === 7000 && $f2['payload']['prepaid_cents'] === 5000, 'facture finale : 120 − 50');
$xp = $xsd(file_get_contents($dir . '/' . $f2['invoice_number'] . '.xml'));
assert($xp->evaluate('string(//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID)') === 'S4', 'facture définitive après acompte : S4');
assert($money($xp, 'TotalPrepaidAmount') === 5000 && $money($xp, 'DuePayableAmount') === 7000 && $money($xp, 'GrandTotalAmount') === 12000);
assert($xp->evaluate('string(//ram:BuyerTradeParty//ram:URIID/@schemeID)') === 'EM' && str_contains($xp->evaluate('string(//rsm:ExchangedDocument)'), 'sans objet (client particulier)'));
assert(isset(D::issue($ref2, 'invoice')['errors']['kind']), 'une seule facture');
assert($f2['ereporting_status'] === 'disabled', 'B2C avant 2027-09-01 : désactivé');

// --- 5. Liste et filtres
$all = D::search();
assert($all['total'] === 5, 'cinq documents : ' . $all['total']);
assert(count(D::search(['kind' => 'deposit'])['items']) === 2 && count(D::search(['state' => 'unpaid'])['items']) === 2);
assert(count(D::search(['q' => 'ACME'])['items']) === 3);

// --- 6. Franchise en base : TVA non applicable (catégorie E + mention), XSD valide
$grav['config']->set('plugins.simple-shop.taxes.small_business.enabled', true);
\Grav\Plugin\SimpleShop\Tax\TaxResolver::reset();
$r3 = D::saveDraft(['customer' => $input['customer'], 'lines' => [['title' => 'Maintenance', 'quantity' => 3, 'unit_price' => '50', 'tax_type' => 'Taux normal']]], 'f');
$i3 = D::issue($r3['reference'], 'invoice')['document'];
assert($i3['total_cents'] === 15000, 'franchise : HT = TTC');
$xp = $xsd(file_get_contents($dir . '/' . $i3['invoice_number'] . '.xml'));
assert($xp->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode)') === 'E'
    && str_contains($xp->evaluate('string(//ram:ExemptionReason)'), '293 B'), 'catégorie E, art. 293 B');
$preview = D::preview($r3['reference']);
assert(strncmp((string) $preview, '%PDF', 4) === 0 && !str_contains($preview, 'factur-x.xml'), 'aperçu : PDF sans Factur-X');

// --- 7. Page publique /document?t=… : jeton, choix du moyen, paiement de test, reçu
use Grav\Plugin\Mvc\Request;
use Grav\Plugin\SimpleShop\Controllers\DocumentController;
$grav['config']->set('plugins.simple-shop.taxes.small_business.enabled', false);
\Grav\Plugin\SimpleShop\Tax\TaxResolver::reset();
$page = function (array $get, array $post = []) use ($grav) {
    $grav['uri']->current = '/document';
    $req = new Request($post === [] ? 'GET' : 'POST', $get, $post, ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST']);
    return (new DocumentController($grav, $req))->show();
};
$r = $page(['t' => str_repeat('0', 32)]);
assert($r->getHttpCode() === 404 && $r->getTwigVars()['not_found'] === true, 'jeton inconnu : 404');
$f2 = D::find($f2['id']);
$r = $page(['t' => $f2['access_token']]);
$v = $r->getTwigVars();
assert($r->getHttpCode() === 200 && $v['payable'] && $v['settlement']['remaining_cents'] === 7000 && array_keys($v['gateways']) === ['test_gateway']
    && $v['selected'] === 'test_gateway' && str_contains($v['gateway_html'], 'execute_document_payment'), 'un seul moyen : formulaire affiché');
$r = $page(['t' => $f2['access_token']], ['action' => 'execute_document_payment', 'method' => 'test_gateway', 'nonce' => 'faux', 'test_payment_status' => 'success']);
assert($r->isRedirect() && D::settlement($f2)['state'] === 'unpaid', 'nonce invalide : rien encaissé');
$r = $page(['t' => $f2['access_token']], ['action' => 'execute_document_payment', 'method' => 'bank_transfer', 'nonce' => 'nonce-shop-document-payment']);
assert(D::settlement($f2)['state'] === 'unpaid', 'moyen non retenu pour ce document : refusé');
$r = $page(['t' => $f2['access_token']], ['action' => 'execute_document_payment', 'method' => 'test_gateway', 'nonce' => 'nonce-shop-document-payment', 'test_payment_status' => 'failure']);
assert(str_contains((string) $r->getRedirectUrl(), 'moyen=test_gateway') && D::settlement($f2)['state'] === 'unpaid', 'échec : rien encaissé');
$r = $page(['t' => $f2['access_token']], ['action' => 'execute_document_payment', 'method' => 'test_gateway', 'nonce' => 'nonce-shop-document-payment', 'test_payment_status' => 'success']);
assert($r->getRedirectUrl() === 'https://shop.test/document?t=' . $f2['access_token'] && D::settlement($f2)['state'] === 'paid', 'paiement de test : réglée');
$pays = L::forInvoice($f2['id']);
assert(count($pays) === 1 && $pays[0]['amount_cents'] === 7000 && $pays[0]['recorded_by'] === 'client' && str_starts_with((string) $pays[0]['external_ref'], 'test-'));
$r = $page(['t' => $f2['access_token']]);
$v = $r->getTwigVars();
assert(!$v['payable'] && $v['paid_url'] !== null && $v['gateway_html'] === '', 'réglée : plus de paiement, copie acquittée proposée');
$r = $page(['t' => $f2['access_token'], 'pdf' => 'paid']);
assert($r->getContentType() === 'application/pdf' && str_starts_with($r->getBody(), '%PDF') && str_contains($r->getHeaders()['Content-Disposition'], '-acquittee.pdf'));
// Plusieurs moyens : liste, sans formulaire tant que rien n'est choisi
$r = $page(['t' => $dep['access_token']]);
// (acompte réglé) → pas de paiement
assert(!$r->getTwigVars()['payable']);
$r4 = D::saveDraft(['customer' => $input['customer'], 'lines' => [['title' => 'X', 'quantity' => 1, 'unit_price' => '10', 'tax_type' => 'Taux normal']]], 'f');
$i4 = D::issue($r4['reference'], 'invoice')['document'];
$r = $page(['t' => $i4['access_token']]);
$v = $r->getTwigVars();
assert(count($v['gateways']) === 2 && $v['selected'] === null && $v['gateway_html'] === '', 'deux moyens : le client choisit');
$r = $page(['t' => $i4['access_token'], 'moyen' => 'bank_transfer']);
$v = $r->getTwigVars();
assert($v['selected'] === 'bank_transfer' && str_contains($v['gateway_html'], 'FR76 3000 6000 0112 3456 7890 189') && str_contains($v['gateway_html'], $i4['invoice_number']), 'virement : IBAN et référence');

// --- 8. Coordonnées connues (pré-remplissage)
$known = D::lookupCustomer('CLIENT@exemple.fr');
assert($known['company'] === 'ACME SAS' && $known['siren'] === '732829320' && $known['type'] === 'b2b');
assert(D::lookupCustomer('inconnu@exemple.fr') === null && D::lookupCustomer('pas un e-mail') === null);

echo "OK\n";
