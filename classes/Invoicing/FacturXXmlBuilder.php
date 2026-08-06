<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

/**
 * Génère le XML CII (Cross Industry Invoice) profil BASIC de Factur-X pour
 * une commande, destiné à être fusionné avec le PDF de base via
 * `Atgp\FacturX\Writer` (voir {@see Invoice}).
 *
 * ⚠️ Ce générateur est écrit à la main (aucun outillage XSD/CII disponible
 * dans cet environnement pour le tester exhaustivement) : il vise une
 * structure standard raisonnable du profil BASIC
 * (`urn:factur-x.eu:1p0:basic`), mais DOIT être re-validé contre le XSD
 * officiel (fourni dans `atgp/factur-x`, dossier `xsd/`) avant toute mise en
 * production. La validation est effectuée par {@see Invoice::build()} via
 * `Atgp\FacturX\XsdValidator`, mais ses éventuelles erreurs ne bloquent
 * volontairement pas la génération : elles sont journalisées dans le fichier
 * dédié `logs/simple-shop/invoices.log` (voir {@see InvoiceLogger}).
 *
 * Simplifications assumées :
 *  - un seul taux de TVA pour toute la facture ;
 *  - catégorie de TVA UNTDID 5305 déterminée heuristiquement : `AA` (taux
 *    réduit) si le taux est inférieur à 20 %, `S` (taux standard) sinon ;
 *  - devise EUR uniquement ;
 *  - client B2C (pas de SIRET/TVA acheteur) ;
 *  - aucune remise/charge au niveau de l'en-tête de facture.
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

    /**
     * Lignes de facture calculées (voir Invoice::computeLines()).
     *
     * @var array
     */
    private array $lines;

    /**
     * @param array $order      Ligne brute de la table `order`.
     * @param array $invoiceRow Ligne de la table `invoice`.
     * @param array $seller     Informations légales du vendeur.
     * @param array $lines      Lignes de facture calculées.
     */
    public function __construct(array $order, array $invoiceRow, array $seller, array $lines)
    {
        $this->order = $order;
        $this->invoiceRow = $invoiceRow;
        $this->seller = $seller;
        $this->lines = $lines;
    }

    /**
     * Construit le document XML CII complet.
     *
     * @return string Le XML (chaîne UTF-8).
     */
    public function build(): string
    {
        $customer = json_decode((string) ($this->order['customer_data'] ?? '{}'), true) ?: [];
        $billing  = json_decode((string) ($this->order['billing_data'] ?? '{}'), true) ?: [];

        $vatRate = (float) ($this->invoiceRow['vat_rate'] ?? 0.0);
        $vatCategoryCode = $vatRate < 0.20 ? 'AA' : 'S';
        $vatRatePercent = round($vatRate * 100, 2);

        $issueDate = date('Ymd', strtotime((string) ($this->order['created_at'] ?? 'now')) ?: time());

        $subtotalHt = 0.0;
        $vatTotal = 0.0;
        $totalTtc = 0.0;

        $linesXml = '';
        $lineNumber = 0;
        foreach ($this->lines as $line) {
            $lineNumber++;
            $subtotalHt += $line['line_total_ht'];
            $vatTotal += $line['line_vat'];
            $totalTtc += $line['line_total_ttc'];

            $linesXml .= $this->buildLineXml($lineNumber, $line, $vatCategoryCode, $vatRatePercent);
        }

        $subtotalHt = round($subtotalHt, 2);
        $vatTotal = round($vatTotal, 2);
        $totalTtc = round($totalTtc, 2);

        $billingName = (string) ($billing['name'] ?? trim(((string) ($customer['firstname'] ?? '')) . ' ' . ((string) ($customer['lastname'] ?? ''))));

        $sellerXml = $this->buildTradePartyXml(
            'SellerTradeParty',
            (string) ($this->seller['name'] ?? ''),
            (string) ($this->seller['address_line1'] ?? ''),
            (string) ($this->seller['address_line2'] ?? ''),
            (string) ($this->seller['postal_code'] ?? ''),
            (string) ($this->seller['city'] ?? ''),
            (string) ($this->seller['country'] ?? 'FR'),
            (string) ($this->seller['siret'] ?? ''),
            (string) ($this->seller['vat_number'] ?? '')
        );

        $buyerXml = $this->buildTradePartyXml(
            'BuyerTradeParty',
            $billingName,
            (string) ($billing['address-line1'] ?? ($customer['address-line1'] ?? '')),
            (string) ($billing['address-line2'] ?? ($customer['address-line2'] ?? '')),
            (string) ($billing['postal-code'] ?? ($customer['postal-code'] ?? '')),
            '',
            (string) ($customer['country'] ?? 'FR'),
            '',
            ''
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rsm:CrossIndustryInvoice '
            . 'xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" '
            . 'xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" '
            . 'xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100" '
            . 'xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">';

        $xml .= '<rsm:ExchangedDocumentContext>'
            . '<ram:GuidelineSpecifiedDocumentContextParameter>'
            . '<ram:ID>urn:factur-x.eu:1p0:basic</ram:ID>'
            . '</ram:GuidelineSpecifiedDocumentContextParameter>'
            . '</rsm:ExchangedDocumentContext>';

        $xml .= '<rsm:ExchangedDocument>'
            . '<ram:ID>' . $this->esc((string) ($this->invoiceRow['invoice_number'] ?? '')) . '</ram:ID>'
            . '<ram:TypeCode>380</ram:TypeCode>'
            . '<ram:IssueDateTime><udt:DateTimeString format="102">' . $issueDate . '</udt:DateTimeString></ram:IssueDateTime>'
            . '</rsm:ExchangedDocument>';

        $xml .= '<rsm:SupplyChainTradeTransaction>';
        $xml .= $linesXml;

        $xml .= '<ram:ApplicableHeaderTradeAgreement>' . $sellerXml . $buyerXml . '</ram:ApplicableHeaderTradeAgreement>';
        $xml .= '<ram:ApplicableHeaderTradeDelivery/>';

        $xml .= '<ram:ApplicableHeaderTradeSettlement>';
        $xml .= '<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>';
        $xml .= '<ram:ApplicableTradeTax>'
            . '<ram:CalculatedAmount>' . number_format($vatTotal, 2, '.', '') . '</ram:CalculatedAmount>'
            . '<ram:TypeCode>VAT</ram:TypeCode>'
            . '<ram:BasisAmount>' . number_format($subtotalHt, 2, '.', '') . '</ram:BasisAmount>'
            . '<ram:CategoryCode>' . $vatCategoryCode . '</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>' . $vatRatePercent . '</ram:RateApplicablePercent>'
            . '</ram:ApplicableTradeTax>';
        $xml .= '<ram:SpecifiedTradeSettlementHeaderMonetarySummation>'
            . '<ram:LineTotalAmount>' . number_format($subtotalHt, 2, '.', '') . '</ram:LineTotalAmount>'
            . '<ram:TaxBasisTotalAmount>' . number_format($subtotalHt, 2, '.', '') . '</ram:TaxBasisTotalAmount>'
            . '<ram:TaxTotalAmount currencyID="EUR">' . number_format($vatTotal, 2, '.', '') . '</ram:TaxTotalAmount>'
            . '<ram:GrandTotalAmount>' . number_format($totalTtc, 2, '.', '') . '</ram:GrandTotalAmount>'
            . '<ram:DuePayableAmount>' . number_format($totalTtc, 2, '.', '') . '</ram:DuePayableAmount>'
            . '</ram:SpecifiedTradeSettlementHeaderMonetarySummation>';
        $xml .= '</ram:ApplicableHeaderTradeSettlement>';

        $xml .= '</rsm:SupplyChainTradeTransaction>';
        $xml .= '</rsm:CrossIndustryInvoice>';

        return $xml;
    }

    /**
     * Construit le XML d'une ligne de facture (`IncludedSupplyChainTradeLineItem`).
     *
     * @param int    $lineNumber      Numéro séquentiel de la ligne (1-indexé).
     * @param array  $line            Ligne calculée (voir Invoice::computeLines()).
     * @param string $vatCategoryCode Code de catégorie de TVA (UNTDID 5305).
     * @param float  $vatRatePercent  Taux de TVA en pourcentage.
     *
     * @return string
     */
    private function buildLineXml(int $lineNumber, array $line, string $vatCategoryCode, float $vatRatePercent): string
    {
        return '<ram:IncludedSupplyChainTradeLineItem>'
            . '<ram:AssociatedDocumentLineDocument><ram:LineID>' . $lineNumber . '</ram:LineID></ram:AssociatedDocumentLineDocument>'
            . '<ram:SpecifiedTradeProduct><ram:Name>' . $this->esc($line['title']) . '</ram:Name></ram:SpecifiedTradeProduct>'
            . '<ram:SpecifiedLineTradeAgreement>'
            . '<ram:NetPriceProductTradePrice><ram:ChargeAmount>' . number_format($line['unit_price_ttc'], 2, '.', '') . '</ram:ChargeAmount></ram:NetPriceProductTradePrice>'
            . '</ram:SpecifiedLineTradeAgreement>'
            . '<ram:SpecifiedLineTradeDelivery>'
            . '<ram:BilledQuantity unitCode="C62">' . (int) $line['quantity'] . '</ram:BilledQuantity>'
            . '</ram:SpecifiedLineTradeDelivery>'
            . '<ram:SpecifiedLineTradeSettlement>'
            . '<ram:ApplicableTradeTax>'
            . '<ram:TypeCode>VAT</ram:TypeCode>'
            . '<ram:CategoryCode>' . $vatCategoryCode . '</ram:CategoryCode>'
            . '<ram:RateApplicablePercent>' . $vatRatePercent . '</ram:RateApplicablePercent>'
            . '</ram:ApplicableTradeTax>'
            . '<ram:SpecifiedTradeSettlementLineMonetarySummation>'
            . '<ram:LineTotalAmount>' . number_format($line['line_total_ht'], 2, '.', '') . '</ram:LineTotalAmount>'
            . '</ram:SpecifiedTradeSettlementLineMonetarySummation>'
            . '</ram:SpecifiedLineTradeSettlement>'
            . '</ram:IncludedSupplyChainTradeLineItem>';
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
        string $siret,
        string $vatNumber
    ): string {
        $xml = '<ram:' . $tag . '>';
        $xml .= '<ram:Name>' . $this->esc($name) . '</ram:Name>';

        if ($siret !== '') {
            $xml .= '<ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . $this->esc($siret) . '</ram:ID></ram:SpecifiedLegalOrganization>';
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
