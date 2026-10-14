<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

use Grav\Plugin\SimpleShop\Orders\OrderAmounts;
use Grav\Plugin\SimpleShop\Services\AddressFields;

use Atgp\FacturX\Writer;
use Atgp\FacturX\XsdValidator;

/**
 * Construit le PDF Factur-X (PDF/A-3 + XML CII embarqué) d'une commande.
 *
 * Deux étapes :
 *  1. {@see self::buildBasePdf()} : construit un PDF de facture "classique"
 *     (texte, tableau) via `setasign/fpdi-fpdf` (FPDF), SANS se préoccuper de
 *     conformité PDF/A — celle-ci est ajoutée ensuite par `Atgp\FacturX\Writer`.
 *  2. {@see self::build()} : génère le XML CII (délégué à
 *     {@see FacturXXmlBuilder}), le valide (best-effort, voir note
 *     ci-dessous) via `Atgp\FacturX\XsdValidator`, puis fusionne PDF de base
 *     + XML via `Atgp\FacturX\Writer::generate()` pour produire le PDF
 *     Factur-X final.
 *
 * ⚠️ LIMITATION ASSUMÉE : le XML CII généré par {@see FacturXXmlBuilder} est
 * un premier jet "best-effort" du profil BASIC de Factur-X, écrit à la main
 * faute d'outillage de test XSD disponible dans cet environnement. La
 * validation XSD est effectuée mais ses éventuelles erreurs sont
 * JOURNALISÉES (voir {@see InvoiceLogger}, fichier dédié
 * `logs/simple-shop/invoices.log`) SANS BLOQUER la génération (un e-mail de
 * confirmation de commande, y compris la facture, ne doit jamais être
 * bloqué par une imperfection de structure XML) — à re-valider avant mise en
 * production réelle contre le XSD officiel (fourni dans le paquet
 * `atgp/factur-x`, dossier `xsd/`), ou via un outil en ligne dédié (voir
 * NOTES-TODO.md : Testeur Factur-X de la FNFE-MPE).
 *
 * Limitations métier assumées (voir échanges de conception) :
 *  - uniquement des clients B2C ;
 *  - une devise par commande (celle du site au moment de la commande).
 * Les taux de TVA sont ceux figés sur chaque ligne de la commande (plusieurs taux possibles).
 *
 * @package Grav\Plugin\SimpleShop\Invoicing
 */
class Invoice
{
    /**
     * Ligne brute de la table `order` (voir OrderManager::getOrder()).
     *
     * @var array
     */
    private array $order;

    /**
     * Lignes de la table `order_item` associées à la commande.
     *
     * @var array
     */
    private array $orderItems;

    /**
     * Ligne de la table `invoice` (voir InvoiceManager::getOrCreateInvoice()).
     *
     * @var array
     */
    private array $invoiceRow;

    /**
     * Informations légales du vendeur (voir InvoiceManager::getSellerData()).
     *
     * @var array
     */
    private array $seller;

    /**
     * @param array $order      Ligne brute de la table `order`.
     * @param array $orderItems Lignes de la table `order_item`.
     * @param array $invoiceRow Ligne de la table `invoice`.
     * @param array $seller     Informations légales du vendeur.
     */
    public function __construct(array $order, array $orderItems, array $invoiceRow, array $seller)
    {
        $this->order = $order;
        $this->orderItems = $orderItems;
        $this->invoiceRow = $invoiceRow;
        $this->seller = $seller;
    }

    /**
     * Construit le PDF Factur-X final (contenu binaire).
     *
     * @return string Le contenu binaire du PDF Factur-X.
     */
    public function build(): string
    {
        $computed = $this->computeLines();

        $basePdf = $this->buildBasePdf($computed['amounts'], $computed['totals']);
        $xml = (new FacturXXmlBuilder($this->order, $this->invoiceRow, $this->seller, $computed['amounts'], $computed['totals']))->build();

        $validator = new XsdValidator();
        if (!$validator->validate($xml)) {
            InvoiceLogger::warning(
                'XML Factur-X potentiellement non conforme au XSD officiel pour la facture '
                . ($this->invoiceRow['invoice_number'] ?? '?') . ' (commande '
                . ($this->order['order_reference'] ?? '?') . ') : '
                . implode(' | ', $validator->getErrors())
            );
        }

        $writer = new Writer();

        return $writer->generate($basePdf, $xml);
    }

    /**
     * Montants de la facture, en centimes : lignes figées à la commande (prix HT/TTC, code et taux de taxe), totaux par
     * taux et montant d'arrondi (voir {@see OrderAmounts}). Une ancienne commande (sans centimes) est relue avec le taux
     * figé sur sa facture (`invoice.vat_rate`).
     *
     * @return array{amounts: array, totals: array}
     */
    private function computeLines(): array
    {
        $rate = isset($this->invoiceRow['vat_rate']) ? (float) $this->invoiceRow['vat_rate'] : null;
        $amounts = OrderAmounts::forStoredOrder($this->order, $this->orderItems, $rate);

        return ['amounts' => $amounts, 'totals' => OrderAmounts::invoiceTotals($amounts)];
    }

    /**
     * Construit le PDF de facture "de base" (mise en page simple : en-tête
     * vendeur, numéro/date, adresse client, tableau des lignes, totaux),
     * SANS conformité PDF/A (ajoutée ensuite par `Atgp\FacturX\Writer`).
     *
     * ⚠️ CORRECTIF (bug de génération) : la classe `\setasign\Fpdi\Fpdf\Fpdi`
     * n'existe pas dans `setasign/fpdi` (voir l'arborescence de
     * `vendor/setasign/fpdi/src/` : aucun sous-namespace `Fpdf`). La classe
     * correcte, combinant `\FPDF` (fourni par `setasign/fpdf`) et les
     * fonctionnalités FPDI, est `\setasign\Fpdi\Fpdi` (fichier `src/Fpdi.php`,
     * à la racine du namespace `setasign\Fpdi`).
     *
     * @param array $amounts Montants de la commande (voir {@see OrderAmounts}).
     * @param array $totals  Totaux de facture (voir {@see OrderAmounts::invoiceTotals()}).
     *
     * @return string Le contenu binaire du PDF de base.
     */
    private function buildBasePdf(array $amounts, array $totals): string
    {
        $cur = ' ' . $amounts['currency'];
        $fmt = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ');
        $customer = AddressFields::normalize(json_decode((string) ($this->order['customer_data'] ?? '{}'), true) ?: []);
        $billing  = AddressFields::normalize(json_decode((string) ($this->order['billing_data'] ?? '{}'), true) ?: []);

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);

        // En-tête vendeur.
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(0, 8, $this->t((string) ($this->seller['name'] ?? '')), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        foreach ([
            (string) ($this->seller['address_line1'] ?? ''),
            (string) ($this->seller['address_line2'] ?? ''),
            trim(((string) ($this->seller['postal_code'] ?? '')) . ' ' . ((string) ($this->seller['city'] ?? ''))),
            (string) ($this->seller['country'] ?? ''),
        ] as $line) {
            if (trim($line) !== '') {
                $pdf->Cell(0, 5, $this->t($line), 0, 1);
            }
        }
        if (!empty($this->seller['siret'])) {
            $pdf->Cell(0, 5, $this->t('SIRET : ' . $this->seller['siret']), 0, 1);
        }
        if (!empty($this->seller['vat_number'])) {
            $pdf->Cell(0, 5, $this->t('TVA intracommunautaire : ' . $this->seller['vat_number']), 0, 1);
        }

        $pdf->Ln(6);

        // Titre + numéro + date + référence commande.
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 10, $this->t('FACTURE ' . (string) ($this->invoiceRow['invoice_number'] ?? '')), 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 10);
        $createdAt = (string) ($this->order['created_at'] ?? 'now');
        $pdf->Cell(0, 5, $this->t('Date : ' . date('d/m/Y', strtotime($createdAt) ?: time())), 0, 1, 'R');
        $pdf->Cell(0, 5, $this->t('Commande : ' . (string) ($this->order['order_reference'] ?? '')), 0, 1, 'R');

        $pdf->Ln(6);

        // Adresse client (facturation, avec repli sur l'adresse client/livraison).
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 6, $this->t('Facturé à :'), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);

        $billingName = (string) ($billing['name'] ?? trim(((string) ($customer['firstname'] ?? '')) . ' ' . ((string) ($customer['lastname'] ?? ''))));
        $addressLines = [
            $billingName,
            (string) ($billing['street'] ?? ($customer['street'] ?? '')),
            (string) ($billing['address-complement'] ?? ($customer['address-complement'] ?? '')),
            trim(((string) ($billing['postal-code'] ?? ($customer['postal-code'] ?? ''))) . ' ' . ((string) ($billing['city'] ?? ($customer['city'] ?? '')))),
            (string) ($customer['country'] ?? ''),
        ];
        foreach ($addressLines as $line) {
            if (trim($line) !== '') {
                $pdf->Cell(0, 5, $this->t($line), 0, 1);
            }
        }

        $pdf->Ln(8);

        // Tableau des lignes.
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(90, 7, $this->t('Designation'), 1, 0, 'L', true);
        $pdf->Cell(20, 7, $this->t('Qte'), 1, 0, 'C', true);
        $pdf->Cell(35, 7, $this->t('PU TTC'), 1, 0, 'R', true);
        $pdf->Cell(35, 7, $this->t('Total TTC'), 1, 1, 'R', true);

        $pdf->SetFont('Helvetica', '', 10);
        foreach ($amounts['lines'] as $line) {
            $pdf->Cell(90, 7, $this->t($line['title']), 1);
            $pdf->Cell(20, 7, (string) $line['quantity'], 1, 0, 'C');
            $pdf->Cell(35, 7, $fmt($line['unit_ttc_cents']) . $cur, 1, 0, 'R');
            $pdf->Cell(35, 7, $fmt($line['line_ttc_cents']) . $cur, 1, 1, 'R');
        }

        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(145, 6, $this->t('Total HT'), 0, 0, 'R');
        $pdf->Cell(35, 6, $fmt($totals['line_total_ht_cents']) . $cur, 0, 1, 'R');
        foreach ($totals['taxes'] as $tax) {
            $percent = rtrim(rtrim(number_format($tax['bp'] / 100, 2, ',', ''), '0'), ',');
            $pdf->Cell(145, 6, $this->t('TVA ' . $percent . ' % sur ' . $fmt($tax['base_cents']) . $cur), 0, 0, 'R');
            $pdf->Cell(35, 6, $fmt($tax['tax_cents']) . $cur, 0, 1, 'R');
        }
        if ($totals['rounding_cents'] !== 0) {
            // Écart d'arrondi entre la TVA calculée par taux et les prix TTC payés (BT-114).
            $pdf->Cell(145, 6, $this->t('Arrondi'), 0, 0, 'R');
            $pdf->Cell(35, 6, ($totals['rounding_cents'] < 0 ? '-' : '') . $fmt(abs($totals['rounding_cents'])) . $cur, 0, 1, 'R');
        }
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(145, 8, $this->t('Total TTC'), 0, 0, 'R');
        $pdf->Cell(35, 8, $fmt($totals['due_cents']) . $cur, 0, 1, 'R');

        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->MultiCell(0, 4, $this->t('Facture electronique au format Factur-X (norme EN 16931). Client particulier (B2C).'));

        return $pdf->Output('S');
    }

    /**
     * Convertit un texte UTF-8 vers l'encodage ISO-8859-1 attendu par les
     * polices standard de FPDF (Helvetica...), avec translittération des
     * caractères accentués non représentables.
     *
     * @param string $text Texte source (UTF-8).
     *
     * @return string Texte converti (ISO-8859-1), ou texte d'origine si la
     *                conversion échoue.
     */
    private function t(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        return $converted !== false ? $converted : $text;
    }
}
