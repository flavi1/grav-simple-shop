<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

use Grav\Plugin\SimpleShop\Orders\OrderAmounts;
use Grav\Plugin\SimpleShop\Services\AddressFields;

/**
 * Génère le XML CII (Cross Industry Invoice) de Factur-X, profil **EN 16931** (`urn:cen.eu:en16931:2017`), fusionné
 * ensuite avec le PDF de base via `Atgp\FacturX\Writer` (voir {@see Invoice}). Validé contre le XSD officiel
 * (`atgp/factur-x`, dossier `xsd/`) par {@see Invoice::build()} et par `tests/invoice.php`.
 *
 * Profil EN 16931 et non BASIC : seul lui porte le montant d'arrondi (BT-114), nécessaire quand les prix sont des TTC
 * ronds (la TVA calculée par taux peut différer d'un centime de la somme des TTC payés).
 *
 * Règles appliquées (montants en centimes, voir {@see OrderAmounts}) :
 *  - prix net d'une ligne (BT-146) = prix unitaire **HT** ; montant de ligne (BT-131) = HT × quantité ;
 *  - un bloc TVA par taux (BG-23) : base = somme des lignes HT, TVA = arrondi(base × taux) ;
 *  - total TTC (BT-112) = HT + TVA ; arrondi (BT-114) = montant encaissé − BT-112 ;
 *  - commande payée en ligne : déjà payé (BT-113) = montant encaissé, reste dû (BT-115) = 0, échéance (BT-9) = date du paiement ;
 *  - catégorie de TVA `S` (taux normal ou réduit, le taux est précisé), `Z` pour un taux nul ;
 *  - client B2C (pas de SIRET/TVA acheteur ; adresse électronique = e-mail), aucune remise ou charge d'en-tête ;
 *  - règles françaises (BR-FR) : cadre de facturation BT-23 (B2 par défaut), mentions escompte / pénalités / frais de
 *    recouvrement (BT-22), SIREN du vendeur (BT-30) et son adresse électronique (BT-34, schéma 0225), livré à (BG-13).
 *
 * @package Grav\Plugin\SimpleShop\Invoicing
 */
class FacturXXmlBuilder
{
    /**
     * Ligne brute de la table `order`.
     *
     * @var array
     */
    private array $order;

    /**
     * Ligne de la table `invoice`.
     *
     * @var array
     */
    private array $invoiceRow;

    /**
     * Informations légales du vendeur.
     *
     * @var array
     */
    private array $seller;

    public const PROFILE_URN = 'urn:cen.eu:en16931:2017';

    /** Montants de la commande (voir OrderAmounts). */
    private array $amounts;

    /** Totaux de facture (voir OrderAmounts::invoiceTotals()). */
    private array $totals;

    public function __construct(array $order, array $invoiceRow, array $seller, array $amounts, array $totals)
    {
        $this->order = $order;
        $this->invoiceRow = $invoiceRow;
        $this->seller = $seller;
        $this->amounts = $amounts;
        $this->totals = $totals;
    }

    /**
     * Construit le document XML CII complet.
     *
     * @return string Le XML (chaîne UTF-8).
     */
    public function build(): string
    {
        $customer = AddressFields::normalize(json_decode((string) ($this->order['customer_data'] ?? '{}'), true) ?: []);
        $billing  = AddressFields::normalize(json_decode((string) ($this->order['billing_data'] ?? '{}'), true) ?: []);
        $currency = $this->esc((string) $this->amounts['currency']);
        $t = $this->totals;

        $issueDate = date('Ymd', strtotime((string) ($this->order['created_at'] ?? 'now')) ?: time());

        $linesXml = '';
        foreach ($this->amounts['lines'] as $i => $line) {
            $linesXml .= $this->buildLineXml($i + 1, $line);
        }

        $billingName = (string) ($billing['name'] ?? trim(((string) ($customer['firstname'] ?? '')) . ' ' . ((string) ($customer['lastname'] ?? ''))));

        $sellerXml = $this->buildTradePartyXml(
            'SellerTradeParty',
            (string) ($this->seller['name'] ?? ''),
            (string) ($this->seller['address_line1'] ?? ''),
            (string) ($this->seller['address_line2'] ?? ''),
            (string) ($this->seller['postal_code'] ?? ''),
            (string) ($this->seller['city'] ?? ''),
            (string) ($this->seller['country'] ?? 'FR'),
            self::siren((string) ($this->seller['siret'] ?? '')),
            (string) ($this->seller['vat_number'] ?? ''),
            // Adresse électronique du vendeur (BT-34, règle BR-FR-13) : son SIREN, schéma 0225 (annuaire PPF).
            self::siren((string) ($this->seller['siret'] ?? '')) !== '' ? ['0225', self::siren((string) $this->seller['siret'])] : null
        );

        $buyerXml = $this->buildTradePartyXml(
            'BuyerTradeParty',
            $billingName,
            (string) ($billing['street'] ?? ($customer['street'] ?? '')),
            (string) ($billing['address-complement'] ?? ($customer['address-complement'] ?? '')),
            (string) ($billing['postal-code'] ?? ($customer['postal-code'] ?? '')),
            (string) ($billing['city'] ?? ($customer['city'] ?? '')),
            (string) ($customer['country'] ?? 'FR'),
            '',
            '',
            // Adresse électronique de l'acheteur (BT-49, règle BR-FR-12) : particulier sans SIREN → son e-mail (schéma EM).
            trim((string) ($customer['email'] ?? '')) !== '' ? ['EM', trim((string) $customer['email'])] : null
        );

        // Livré à (BG-13) : point de retrait choisi, sinon l'adresse du client.
        $shipping = json_decode((string) ($this->order['shipping_data'] ?? '{}'), true) ?: [];
        $point = is_array($shipping['pickup_point'] ?? null) ? $shipping['pickup_point'] : null;
        $shipToXml = $this->buildTradePartyXml(
            'ShipToTradeParty',
            $point ? (string) ($point['name'] ?? $billingName) : trim(((string) ($customer['firstname'] ?? '')) . ' ' . ((string) ($customer['lastname'] ?? ''))),
            (string) ($point['street'] ?? ($customer['street'] ?? '')),
            $point ? '' : (string) ($customer['address-complement'] ?? ''),
            (string) ($point['postal-code'] ?? ($customer['postal-code'] ?? '')),
            (string) ($point['city'] ?? ($customer['city'] ?? '')),
            (string) ($customer['country'] ?? 'FR'),
            '',
            ''
        );

        // Mentions (BT-22) : escompte, pénalités de retard, frais de recouvrement (règle BR-FR-05).
        $notesXml = '';
        foreach ((array) ($this->seller['notes'] ?? []) as $code => $text) {
            if (in_array($code, ['AAB', 'PMD', 'PMT'], true) && trim((string) $text) !== '') {
                $notesXml .= '<ram:IncludedNote><ram:Content>' . $this->esc(trim((string) $text)) . '</ram:Content>'
                    . '<ram:SubjectCode>' . $code . '</ram:SubjectCode></ram:IncludedNote>';
            }
        }
        // Cadre de facturation (BT-23, règle BR-FR-08) : B2 = biens, facture déjà payée.
        $billingMode = (string) ($this->seller['billing_mode'] ?? 'B2');
        $billingMode = preg_match('/^[BSM][1-9]$/', $billingMode) ? $billingMode : 'B2';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rsm:CrossIndustryInvoice '
            . 'xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" '
            . 'xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" '
            . 'xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100" '
            . 'xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">';

        $xml .= '<rsm:ExchangedDocumentContext>'
            . '<ram:BusinessProcessSpecifiedDocumentContextParameter><ram:ID>' . $billingMode . '</ram:ID></ram:BusinessProcessSpecifiedDocumentContextParameter>'
            . '<ram:GuidelineSpecifiedDocumentContextParameter>'
            . '<ram:ID>' . self::PROFILE_URN . '</ram:ID>'
            . '</ram:GuidelineSpecifiedDocumentContextParameter>'
            . '</rsm:ExchangedDocumentContext>';

        $xml .= '<rsm:ExchangedDocument>'
            . '<ram:ID>' . $this->esc((string) ($this->invoiceRow['invoice_number'] ?? '')) . '</ram:ID>'
            . '<ram:TypeCode>380</ram:TypeCode>'
            . '<ram:IssueDateTime><udt:DateTimeString format="102">' . $issueDate . '</udt:DateTimeString></ram:IssueDateTime>'
            . $notesXml
            . '</rsm:ExchangedDocument>';

        $xml .= '<rsm:SupplyChainTradeTransaction>';
        $xml .= $linesXml;

        $xml .= '<ram:ApplicableHeaderTradeAgreement>' . $sellerXml . $buyerXml . '</ram:ApplicableHeaderTradeAgreement>';
        $xml .= '<ram:ApplicableHeaderTradeDelivery>' . $shipToXml . '</ram:ApplicableHeaderTradeDelivery>';

        $xml .= '<ram:ApplicableHeaderTradeSettlement>';
        $xml .= '<ram:InvoiceCurrencyCode>' . $currency . '</ram:InvoiceCurrencyCode>';
        foreach ($t['taxes'] as $tax) {
            $xml .= '<ram:ApplicableTradeTax>'
                . '<ram:CalculatedAmount>' . self::amount($tax['tax_cents']) . '</ram:CalculatedAmount>'
                . '<ram:TypeCode>VAT</ram:TypeCode>'
                // Exonération (catégorie E) : motif obligatoire (BT-120, règle BR-E-10).
                . (($tax['category'] ?? '') === 'E' ? '<ram:ExemptionReason>' . $this->esc((string) ($tax['mention'] ?: 'Exonération de TVA')) . '</ram:ExemptionReason>' : '')
                . '<ram:BasisAmount>' . self::amount($tax['base_cents']) . '</ram:BasisAmount>'
                . '<ram:CategoryCode>' . $this->esc((string) ($tax['category'] ?? self::category($tax['bp']))) . '</ram:CategoryCode>'
                . '<ram:RateApplicablePercent>' . self::percent($tax['bp']) . '</ram:RateApplicablePercent>'
                . '</ram:ApplicableTradeTax>';
        }
        // Échéance (BT-9) = date du paiement : commande payée en ligne à sa création (règle BR-FR-CO-09 pour B2/S2/M2).
        $xml .= '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">' . $issueDate
            . '</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>';
        $xml .= '<ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
            . '<ram:LineTotalAmount>' . self::amount($t['line_total_ht_cents']) . '</ram:LineTotalAmount>'
            . '<ram:TaxBasisTotalAmount>' . self::amount($t['line_total_ht_cents']) . '</ram:TaxBasisTotalAmount>'
            . '<ram:TaxTotalAmount currencyID="' . $currency . '">' . self::amount($t['tax_total_cents']) . '</ram:TaxTotalAmount>'
            . ($t['rounding_cents'] !== 0 ? '<ram:RoundingAmount>' . self::amount($t['rounding_cents']) . '</ram:RoundingAmount>' : '')
            . '<ram:GrandTotalAmount>' . self::amount($t['grand_total_cents']) . '</ram:GrandTotalAmount>'
            . '<ram:TotalPrepaidAmount>' . self::amount($t['due_cents']) . '</ram:TotalPrepaidAmount>'
            . '<ram:DuePayableAmount>0.00</ram:DuePayableAmount>'
            . '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>';
        $xml .= '</ram:ApplicableHeaderTradeSettlement>';

        $xml .= '</rsm:SupplyChainTradeTransaction>';
        $xml .= '</rsm:CrossIndustryInvoice>';

        return $xml;
    }

    /** Ligne de facture (`IncludedSupplyChainTradeLineItem`) : prix net HT, quantité, taux, montant HT. */
    private function buildLineXml(int $lineNumber, array $line): string
    {
        return '<ram:IncludedSupplyChainTradeLineItem>'
            . '<ram:AssociatedDocumentLineDocument><ram:LineID>' . $lineNumber . '</ram:LineID></ram:AssociatedDocumentLineDocument>'
            . '<ram:SpecifiedTradeProduct><ram:Name>' . $this->esc($line['title']) . '</ram:Name></ram:SpecifiedTradeProduct>'
            . '<ram:SpecifiedLineTradeAgreement>'
            . '<ram:NetPriceProductTradePrice><ram:ChargeAmount>' . self::amount($line['unit_ht_cents']) . '</ram:ChargeAmount></ram:NetPriceProductTradePrice>'
            . '</ram:SpecifiedLineTradeAgreement>'
            . '<ram:SpecifiedLineTradeDelivery>'
            . '<ram:BilledQuantity unitCode="C62">' . (int) $line['quantity'] . '</ram:BilledQuantity>'
            . '</ram:SpecifiedLineTradeDelivery>'
            . '<ram:SpecifiedLineTradeSettlement>'
            . '<ram:ApplicableTradeTax>'
            . '<ram:TypeCode>VAT</ram:TypeCode>'
            . '<ram:CategoryCode>' . $this->esc((string) ($line['taxes'][0]['category'] ?? self::category($line['tax_bp']))) . '</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>' . self::percent((int) ($line['taxes'][0]['bp'] ?? $line['tax_bp'])) . '</ram:RateApplicablePercent>'
            . '</ram:ApplicableTradeTax>'
            . '<ram:SpecifiedTradeSettlementLineMonetarySummation>'
            . '<ram:LineTotalAmount>' . self::amount($line['line_ht_cents']) . '</ram:LineTotalAmount>'
            . '</ram:SpecifiedTradeSettlementLineMonetarySummation>'
            . '</ram:SpecifiedLineTradeSettlement>'
            . '</ram:IncludedSupplyChainTradeLineItem>';
    }

    /** SIREN (9 chiffres) tiré d'un SIRET (14 chiffres) ou d'un SIREN saisi ; chaîne vide si illisible. */
    public static function siren(string $siretOrSiren): string
    {
        $digits = preg_replace('/\D/', '', $siretOrSiren);

        return strlen($digits) === 14 || strlen($digits) === 9 ? substr($digits, 0, 9) : '';
    }

    /** Centimes → « 12.95 » (montants négatifs : « -0.01 »). */
    private static function amount(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Points de base → pourcentage (550 → « 5.50 »). */
    private static function percent(int $bp): string
    {
        return self::amount($bp);
    }

    /** Catégorie de TVA EN 16931 (UNTDID 5305 restreinte) : S = taux normal ou réduit, Z = taux nul. */
    private static function category(int $bp): string
    {
        return $bp > 0 ? 'S' : 'Z';
    }

    /**
     * Construit le XML d'une partie commerciale (vendeur ou acheteur).
     *
     * @param string $tag           Nom de la balise (`SellerTradeParty` ou `BuyerTradeParty`).
     * @param string $name          Raison sociale / nom.
     * @param string $addressLine1  Adresse (ligne 1).
     * @param string $addressLine2  Adresse (ligne 2).
     * @param string $postalCode    Code postal.
     * @param string $city          Ville.
     * @param string $country       Code pays ISO (ex. FR).
     * @param string $siret         SIRET (vendeur uniquement, vide sinon).
     * @param string $vatNumber     Numéro de TVA intracommunautaire (vendeur uniquement, vide sinon).
     *
     * @return string
     */
    private function buildTradePartyXml(
        string $tag,
        string $name,
        string $addressLine1,
        string $addressLine2,
        string $postalCode,
        string $city,
        string $country,
        string $siren,
        string $vatNumber,
        ?array $endpoint = null
    ): string {
        $xml = '<ram:' . $tag . '>';
        $xml .= '<ram:Name>' . $this->esc($name) . '</ram:Name>';

        // Immatriculation légale (BT-30) : SIREN, schéma 0002 (règle BR-FR-10 : 9 chiffres).
        if ($siren !== '') {
            $xml .= '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . $this->esc($siren) . '</ram:ID></ram:SpecifiedLegalOrganization>';
        }

        $xml .= '<ram:PostalTradeAddress>';
        if ($postalCode !== '') {
            $xml .= '<ram:PostcodeCode>' . $this->esc($postalCode) . '</ram:PostcodeCode>';
        }
        if ($addressLine1 !== '') {
            $xml .= '<ram:LineOne>' . $this->esc($addressLine1) . '</ram:LineOne>';
        }
        if ($addressLine2 !== '') {
            $xml .= '<ram:LineTwo>' . $this->esc($addressLine2) . '</ram:LineTwo>';
        }
        if ($city !== '') {
            $xml .= '<ram:CityName>' . $this->esc($city) . '</ram:CityName>';
        }
        $xml .= '<ram:CountryID>' . $this->esc($country !== '' ? $country : 'FR') . '</ram:CountryID>';
        $xml .= '</ram:PostalTradeAddress>';

        if ($endpoint !== null) {
            $xml .= '<ram:URIUniversalCommunication><ram:URIID schemeID="' . $this->esc($endpoint[0]) . '">' . $this->esc($endpoint[1])
                . '</ram:URIID></ram:URIUniversalCommunication>';
        }

        if ($vatNumber !== '') {
            $xml .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . $this->esc($vatNumber) . '</ram:ID></ram:SpecifiedTaxRegistration>';
        }

        $xml .= '</ram:' . $tag . '>';

        return $xml;
    }

    /**
     * Échappe une valeur pour insertion dans le XML.
     *
     * @param string $value Valeur à échapper.
     *
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
