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
 * AGENTS.md : Testeur Factur-X de la FNFE-MPE ; validé le 15/10/2026).
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
    /**
     * Document (voir FacturXXmlBuilder::documentDefaults()) et éléments d'affichage propres au PDF :
     *  - `title` (« FACTURE », « FACTURE D'ACOMPTE », « DEMANDE DE PAIEMENT »…), `number_label` ;
     *  - `prices_ht` : colonnes HT (client professionnel) au lieu de TTC ;
     *  - `info_lines` : lignes d'en-tête supplémentaires (échéance, référence client…) ;
     *  - `deductions` : [['label', 'cents']] affichées sous le total (acomptes déjà facturés) ;
     *  - `footer` : textes de bas de page (conditions, moyens de paiement, IBAN, mentions) ;
     *  - `stamp` : mention en travers (« ACQUITTÉE le … ») ; `watermark` : « APERÇU » (brouillon, sans valeur) ;
     *  - `no_xml` : PDF simple, sans Factur-X (demande de paiement, aperçu) ;
     *  - `amounts` : montants déjà calculés (lignes figées d'un document), sinon ceux de la commande.
     */
    private array $doc;

    public function __construct(array $order, array $orderItems, array $invoiceRow, array $seller, array $doc = [])
    {
        $this->order = $order;
        $this->orderItems = $orderItems;
        $this->invoiceRow = $invoiceRow;
        $this->seller = $seller;
        $this->doc = $doc;
    }

    /**
     * Construit le PDF Factur-X final (contenu binaire).
     *
     * @return string Le contenu binaire du PDF Factur-X.
     */
    public function build(): string
    {
        return $this->buildParts()['pdf'];
    }

    /**
     * PDF Factur-X et son XML (le XML est conservé à l'émission : la copie « acquittée » embarque exactement le même,
     * voir {@see self::withStoredXml()}).
     *
     * @return array{pdf: string, xml: ?string}
     */
    public function buildParts(): array
    {
        $computed = $this->computeLines();

        $basePdf = $this->buildBasePdf($computed['amounts'], $computed['totals']);
        if (!empty($this->doc['no_xml'])) {
            return ['pdf' => $basePdf, 'xml' => null];
        }
        if (!$computed['totals']['en16931']) {
            // Plusieurs taxes sur une même ligne (taxes cumulées, hors TVA française) : non exprimable en EN 16931.
            InvoiceLogger::warning('Facture ' . ($this->invoiceRow['invoice_number'] ?? '?') . ' : plusieurs taxes sur une ligne, PDF sans XML Factur-X.');

            return ['pdf' => $basePdf, 'xml' => null];
        }
        $xml = (new FacturXXmlBuilder($this->order, $this->invoiceRow, $this->seller, $computed['amounts'], $computed['totals'], $this->doc))->build();

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

        return ['pdf' => $writer->generate($basePdf, $xml), 'xml' => $xml];
    }

    /** Même facture, autre présentation (ex. copie acquittée) : nouveau PDF visible, XML d'origine embarqué tel quel. */
    public function withStoredXml(?string $xml): string
    {
        $computed = $this->computeLines();
        $basePdf = $this->buildBasePdf($computed['amounts'], $computed['totals']);

        return $xml !== null && $xml !== '' ? (new Writer())->generate($basePdf, $xml) : $basePdf;
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
        if (isset($this->doc['amounts']) && is_array($this->doc['amounts'])) {
            $amounts = $this->doc['amounts'];
        } else {
            $rate = isset($this->invoiceRow['vat_rate']) ? (float) $this->invoiceRow['vat_rate'] : null;
            $amounts = OrderAmounts::forStoredOrder($this->order, $this->orderItems, $rate);
        }

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

        // PDF/A-3 (ISO 19005-3, 6.2.11.4.1) : toutes les polices doivent être embarquées. Les 14 polices standard
        // (Helvetica…) ne le sont jamais : on utilise Liberation Sans (licence SIL OFL, métriques identiques à
        // Helvetica/Arial), embarquée depuis fonts/ (définitions FPDF générées par makefont, codage cp1252).
        $pdf = new class () extends \setasign\Fpdi\Fpdi {
            public function useFontDir(string $dir): void
            {
                $this->fontpath = rtrim($dir, '/') . '/';
            }

            /** Revient sur une page déjà écrite (mention en travers posée après le contenu). */
            public function gotoPage(int $n): void
            {
                $this->page = max(1, min($n, count($this->pages)));
            }

            /** Page courante (restaurée après la mention). */
            public function lastPage(): int
            {
                return count($this->pages);
            }
        };
        $pdf->useFontDir(dirname(__DIR__, 2) . '/fonts');
        $pdf->AddFont('LiberationSans', '', 'LiberationSans-Regular.php');
        $pdf->AddFont('LiberationSans', 'B', 'LiberationSans-Bold.php');
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);

        // En-tête vendeur.
        $pdf->SetFont('LiberationSans', 'B', 14);
        $pdf->Cell(0, 8, $this->t((string) ($this->seller['name'] ?? '')), 0, 1);
        $pdf->SetFont('LiberationSans', '', 10);
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
            $digits = preg_replace('/\D/', '', (string) $this->seller['siret']);
            $pdf->Cell(0, 5, $this->t((strlen($digits) === 9 ? 'SIREN : ' : 'SIRET : ') . $this->seller['siret']), 0, 1);
        }
        if (!empty($this->seller['vat_number'])) {
            $pdf->Cell(0, 5, $this->t('TVA intracommunautaire : ' . $this->seller['vat_number']), 0, 1);
        }

        $pdf->Ln(6);

        $d = $this->doc;
        $issue = isset($d['issue_date']) && preg_match('/^\d{8}$/', (string) $d['issue_date'])
            ? substr((string) $d['issue_date'], 6, 2) . '/' . substr((string) $d['issue_date'], 4, 2) . '/' . substr((string) $d['issue_date'], 0, 4)
            : date('d/m/Y', strtotime((string) ($this->order['created_at'] ?? 'now')) ?: time());
        $ht = !empty($d['prices_ht']);

        // Titre + numéro + date + référence commande.
        $pdf->SetFont('LiberationSans', 'B', 16);
        $pdf->Cell(0, 10, $this->t(trim(((string) ($d['title'] ?? 'FACTURE')) . ' ' . (string) ($this->invoiceRow['invoice_number'] ?? ''))), 0, 1, 'R');
        $pdf->SetFont('LiberationSans', '', 10);
        $pdf->Cell(0, 5, $this->t('Date : ' . $issue), 0, 1, 'R');
        $pdf->Cell(0, 5, $this->t('Commande : ' . (string) ($this->order['order_reference'] ?? '')), 0, 1, 'R');
        foreach ((array) ($d['info_lines'] ?? []) as $info) {
            if (trim((string) $info) !== '') {
                $pdf->Cell(0, 5, $this->t((string) $info), 0, 1, 'R');
            }
        }

        $pdf->Ln(6);

        // Adresse client (facturation, avec repli sur l'adresse client/livraison).
        $pdf->SetFont('LiberationSans', 'B', 11);
        $pdf->Cell(0, 6, $this->t('Facturé à :'), 0, 1);
        $pdf->SetFont('LiberationSans', '', 10);

        if (isset($d['buyer']) && is_array($d['buyer'])) {
            $by = $d['buyer'];
            $addressLines = [
                (string) ($by['name'] ?? ''),
                (string) ($by['contact'] ?? ''),
                (string) ($by['street'] ?? ''),
                (string) ($by['complement'] ?? ''),
                trim(((string) ($by['postal_code'] ?? '')) . ' ' . ((string) ($by['city'] ?? ''))),
                (string) ($by['country'] ?? '') !== 'FR' ? (string) ($by['country'] ?? '') : '',
                trim((string) ($by['siren'] ?? '')) !== '' ? 'SIREN : ' . $by['siren'] : '',
                trim((string) ($by['vat'] ?? '')) !== '' ? 'TVA intracommunautaire : ' . $by['vat'] : '',
            ];
        } else {
            $billingName = (string) ($billing['name'] ?? trim(((string) ($customer['firstname'] ?? '')) . ' ' . ((string) ($customer['lastname'] ?? ''))));
            $addressLines = [
                $billingName,
                (string) ($billing['street'] ?? ($customer['street'] ?? '')),
                (string) ($billing['address-complement'] ?? ($customer['address-complement'] ?? '')),
                trim(((string) ($billing['postal-code'] ?? ($customer['postal-code'] ?? ''))) . ' ' . ((string) ($billing['city'] ?? ($customer['city'] ?? '')))),
                (string) ($customer['country'] ?? ''),
            ];
        }
        foreach ($addressLines as $line) {
            if (trim($line) !== '') {
                $pdf->Cell(0, 5, $this->t($line), 0, 1);
            }
        }

        $pdf->Ln(8);

        // Tableau des lignes : TTC (particulier) ou HT avec taux (professionnel).
        $pdf->SetFont('LiberationSans', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($ht ? 80 : 90, 7, $this->t('Désignation'), 1, 0, 'L', true);
        $pdf->Cell($ht ? 15 : 20, 7, $this->t('Qté'), 1, 0, 'C', true);
        $pdf->Cell($ht ? 30 : 35, 7, $this->t($ht ? 'PU HT' : 'PU TTC'), 1, 0, 'R', true);
        if ($ht) {
            $pdf->Cell(20, 7, $this->t('TVA'), 1, 0, 'R', true);
        }
        $pdf->Cell($ht ? 35 : 35, 7, $this->t($ht ? 'Total HT' : 'Total TTC'), 1, 1, 'R', true);

        $pdf->SetFont('LiberationSans', '', 10);
        foreach ($amounts['lines'] as $line) {
            // Désignation sur plusieurs lignes si besoin (un libellé long débordait sur la colonne Qté).
            $wrapped = $this->wrap($pdf, $this->t($line['title']), $ht ? 78 : 88);
            $h = 7 * max(1, count($wrapped));
            if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 25) {
                $pdf->AddPage();
            }
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->MultiCell($ht ? 80 : 90, 7, implode("\n", $wrapped), 1, 'L');
            $pdf->SetXY($x + ($ht ? 80 : 90), $y);
            $pdf->Cell($ht ? 15 : 20, $h, (string) $line['quantity'], 1, 0, 'C');
            if ($ht) {
                $pdf->Cell(30, $h, $fmt($line['unit_ht_cents']) . $cur, 1, 0, 'R');
                $pdf->Cell(20, $h, $this->t(rtrim(rtrim(number_format(((int) $line['tax_bp']) / 100, 2, ',', ''), '0'), ',') . ' %'), 1, 0, 'R');
                $pdf->Cell(35, $h, $fmt($line['line_ht_cents']) . $cur, 1, 1, 'R');
            } else {
                $pdf->Cell(35, $h, $fmt($line['unit_ttc_cents']) . $cur, 1, 0, 'R');
                $pdf->Cell(35, $h, $fmt($line['line_ttc_cents']) . $cur, 1, 1, 'R');
            }
        }

        $pdf->Ln(4);

        $pdf->SetFont('LiberationSans', '', 10);
        $pdf->Cell(145, 6, $this->t('Total HT'), 0, 0, 'R');
        $pdf->Cell(35, 6, $fmt($totals['line_total_ht_cents']) . $cur, 0, 1, 'R');
        foreach ($totals['taxes'] as $tax) {
            $pdf->Cell(145, 6, $this->t(($tax['name'] ?? 'TVA') . ' sur ' . $fmt($tax['base_cents']) . $cur), 0, 0, 'R');
            $pdf->Cell(35, 6, $fmt($tax['tax_cents']) . $cur, 0, 1, 'R');
        }
        if ($totals['rounding_cents'] !== 0) {
            // Écart d'arrondi entre la TVA calculée par taux et les prix TTC payés (BT-114).
            $pdf->Cell(145, 6, $this->t('Arrondi'), 0, 0, 'R');
            $pdf->Cell(35, 6, ($totals['rounding_cents'] < 0 ? '-' : '') . $fmt(abs($totals['rounding_cents'])) . $cur, 0, 1, 'R');
        }
        $pdf->SetFont('LiberationSans', 'B', 11);
        $pdf->Cell(145, 8, $this->t('Total TTC'), 0, 0, 'R');
        $pdf->Cell(35, 8, $fmt($totals['due_cents']) . $cur, 0, 1, 'R');

        // Acomptes déjà facturés et réglés, puis reste à payer (factures à régler seulement).
        $prepaid = isset($d['prepaid_cents']) ? (int) $d['prepaid_cents'] : null;
        if ($prepaid !== null) {
            $pdf->SetFont('LiberationSans', '', 10);
            foreach ((array) ($d['deductions'] ?? []) as $ded) {
                $pdf->Cell(145, 6, $this->t((string) $ded['label']), 0, 0, 'R');
                $pdf->Cell(35, 6, '-' . $fmt((int) $ded['cents']) . $cur, 0, 1, 'R');
            }
            $pdf->SetFont('LiberationSans', 'B', 11);
            $pdf->Cell(145, 8, $this->t('Net à payer'), 0, 0, 'R');
            $pdf->Cell(35, 8, $fmt($totals['grand_total_cents'] - $prepaid + $totals['rounding_cents']) . $cur, 0, 1, 'R');
        }

        $pdf->Ln(8);
        $pdf->SetFont('LiberationSans', '', 8);
        if (isset($d['footer'])) {
            foreach ((array) $d['footer'] as $text) {
                if (trim((string) $text) !== '') {
                    $pdf->MultiCell(0, 4, $this->t((string) $text));
                }
            }
        } else {
            $pdf->MultiCell(0, 4, $this->t('Facture électronique au format Factur-X (norme EN 16931). Client particulier (B2C). Facture payée le ' . $issue . ' (à la commande).'));
            foreach ((array) ($this->seller['notes'] ?? []) as $note) {
                $pdf->MultiCell(0, 4, $this->t((string) $note));
            }
        }
        // Mentions d'exonération (ex. « TVA non applicable, art. 261-4-4°-a du CGI », franchise en base).
        foreach ($totals['mentions'] ?? [] as $mention) {
            $pdf->MultiCell(0, 4, $this->t((string) $mention));
        }

        // Mention en travers : copie acquittée, ou aperçu sans valeur.
        foreach (['stamp' => [0, 128, 0], 'watermark' => [200, 200, 200]] as $key => $rgb) {
            if (trim((string) ($d[$key] ?? '')) === '') {
                continue;
            }
            $last = $pdf->lastPage();
            $pdf->SetAutoPageBreak(false);
            $pdf->gotoPage(1);
            $pdf->SetFont('LiberationSans', 'B', $key === 'stamp' ? 20 : 48);
            $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
            $pdf->SetXY(15, $key === 'stamp' ? 112 : 150);
            $pdf->Cell(0, 14, $this->t((string) $d[$key]), $key === 'stamp' ? 1 : 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->gotoPage($last);
            $pdf->SetAutoPageBreak(true, 20);
        }

        return $pdf->Output('S');
    }

    /**
     * Convertit un texte UTF-8 vers l'encodage ISO-8859-1 attendu par les
     * polices de FPDF (codage cp1252), avec translittération des
     * caractères accentués non représentables.
     *
     * @param string $text Texte source (UTF-8).
     *
     * @return string Texte converti (ISO-8859-1), ou texte d'origine si la
     *                conversion échoue.
     */
    /** Découpe un texte (déjà converti en cp1252) en lignes d'au plus $width mm dans la police courante. */
    private function wrap($pdf, string $text, float $width): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $pdf->GetStringWidth($try) > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function t(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);

        return $converted !== false ? $converted : $text;
    }
}
