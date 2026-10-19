<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Gateways\BankTransferGateway;
use Grav\Plugin\SimpleShop\Gateways\GatewayRegistry;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Orders\OrderAmounts;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger;
use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Tax\TaxResolver;

/**
 * Documents saisis dans l'administration (docs/CONCEPTION-FACTURATION.md) : une **commande manuelle** (lignes libres,
 * client, conditions) d'abord en brouillon, puis ses documents :
 *  - `payment_request` : demande de paiement (suite `DP-`, sans valeur fiscale) ; réglée, elle donne la facture ;
 *  - `invoice` : facture à régler (Factur-X 380) ; facture finale si des acomptes ont été facturés (déduits) ;
 *  - `deposit` : facture d'acompte (Factur-X 386), pourcentage ou montant de la commande ;
 *  - `credit_note` : avoir (Factur-X 381) — prévu, pas encore émis depuis l'administration.
 *
 * Un document émis ne change plus : son contenu est figé dans `invoice.payload` (client, lignes, conditions, montants)
 * et son XML Factur-X est conservé à côté du PDF ; la copie « acquittée » reprend ce même XML.
 *
 * Paiements : table `payment` (Payments\PaymentLedger), rattachés au document payé ; l'état de règlement d'un document
 * compte aussi les paiements du document dont il est issu (facture émise au règlement d'une demande de paiement).
 */
final class DocumentManager
{
    public const KINDS = [
        'invoice'         => 'Facture',
        'deposit'         => "Facture d'acompte",
        'payment_request' => 'Demande de paiement',
        'credit_note'     => 'Avoir',
    ];

    private const TITLES = [
        'invoice'         => 'FACTURE',
        'deposit'         => "FACTURE D'ACOMPTE",
        'payment_request' => 'DEMANDE DE PAIEMENT',
        'credit_note'     => 'AVOIR',
    ];

    private const TYPE_CODES = ['invoice' => '380', 'deposit' => '386', 'credit_note' => '381'];

    /** Nature des opérations → lettre du cadre de facturation BT-23 (B biens, S services, M mixte). */
    private const NATURES = ['goods' => 'B', 'services' => 'S', 'mixed' => 'M'];

    public const NATURE_LABELS = ['services' => 'Prestations de services', 'goods' => 'Livraison de biens', 'mixed' => 'Biens et services'];

    /** Champs client acceptés (en plus de AddressFields). */
    private const CUSTOMER_FIELDS = ['type', 'email', 'company', 'siren', 'vat', 'firstname', 'lastname', 'tel', 'street', 'address-complement',
        'postal-code', 'city', 'country'];

    public static function enabled(): bool
    {
        return (bool) self::config('invoicing.manual', false);
    }

    // ------------------------------------------------------------------
    // Commande manuelle (brouillon)
    // ------------------------------------------------------------------

    /**
     * Valide la saisie d'une commande manuelle.
     *
     * Entrée : `customer` (type b2b|b2c, email, company, siren, vat, firstname, lastname, tel, street, address-complement,
     * postal-code, city, country), `price_basis` (ht|ttc), `lines` [{title, quantity, unit_price (« 120.50 »), tax_type}],
     * `due_days` ou `due_date` (AAAA-MM-JJ), `methods` (identifiants de passerelles), `comment`, `operation_nature`.
     *
     * @return array{0: array, 1: array<string, string>} [données nettoyées, erreurs par champ]
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $c = [];
        foreach (self::CUSTOMER_FIELDS as $f) {
            $c[$f] = mb_substr(trim((string) ($in['customer'][$f] ?? '')), 0, 200);
        }
        $c['type'] = $c['type'] === 'b2b' ? 'b2b' : 'b2c';
        $c['country'] = strtoupper($c['country'] !== '' ? $c['country'] : 'FR');
        $c['siren'] = preg_replace('/\D/', '', $c['siren']);
        $c['vat'] = strtoupper(preg_replace('/\s+/', '', $c['vat']));
        if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['customer.email'] = 'Adresse e-mail valide requise (le document est envoyé à cette adresse).';
        }
        if ($c['type'] === 'b2b' && $c['company'] === '') {
            $errors['customer.company'] = 'Raison sociale requise pour une organisation.';
        }
        if ($c['type'] === 'b2c' && ($c['firstname'] === '' && $c['lastname'] === '')) {
            $errors['customer.lastname'] = 'Nom requis pour un particulier.';
        }
        if ($c['siren'] !== '' && !in_array(strlen($c['siren']), [9, 14], true)) {
            $errors['customer.siren'] = 'SIREN : 9 chiffres (ou les 14 du SIRET).';
        }
        if ($c['vat'] !== '' && !preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $c['vat'])) {
            $errors['customer.vat'] = 'Numéro de TVA intracommunautaire invalide (ex. FR12345678901).';
        }
        foreach (['street' => 'Adresse', 'postal-code' => 'Code postal', 'city' => 'Ville'] as $f => $label) {
            if ($c[$f] === '') {
                $errors['customer.' . $f] = $label . ' requis(e) (adresse de facturation).';
            }
        }

        $basis = ($in['price_basis'] ?? '') === 'ttc' ? 'ttc' : (($in['price_basis'] ?? '') === 'ht' ? 'ht' : ($c['type'] === 'b2b' ? 'ht' : 'ttc'));
        $lines = [];
        foreach (array_values((array) ($in['lines'] ?? [])) as $i => $l) {
            $title = mb_substr(trim((string) ($l['title'] ?? '')), 0, 300);
            $qty = (int) ($l['quantity'] ?? 1);
            $price = self::parseAmount((string) ($l['unit_price'] ?? ''));
            $tax = (string) ($l['tax_type'] ?? '');
            if ($title === '' && $price === null) {
                continue;   // ligne vide (formulaire)
            }
            if ($title === '') {
                $errors["lines.{$i}.title"] = 'Intitulé requis.';
            }
            if ($qty < 1 || $qty > 100000) {
                $errors["lines.{$i}.quantity"] = 'Quantité entière de 1 à 100 000.';
            }
            if ($price === null) {
                $errors["lines.{$i}.unit_price"] = 'Prix unitaire positif, deux décimales au plus.';
            }
            $lines[] = ['title' => $title, 'quantity' => $qty, 'unit_price_cents' => (int) $price, 'tax_type' => $tax];
        }
        if ($lines === []) {
            $errors['lines'] = 'Au moins une ligne.';
        }

        $dueDate = trim((string) ($in['due_date'] ?? ''));
        if ($dueDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || strtotime($dueDate) === false)) {
            $errors['due_date'] = 'Date au format AAAA-MM-JJ.';
        }
        $dueDays = isset($in['due_days']) && $in['due_days'] !== '' ? (int) $in['due_days'] : (int) self::config('invoicing.payment_terms_days', 30);
        if ($dueDays < 0 || $dueDays > 365) {
            $errors['due_days'] = 'Échéance de 0 à 365 jours.';
        }
        $available = array_keys(GatewayRegistry::forDocuments());
        $methods = array_values(array_intersect(array_map('strval', (array) ($in['methods'] ?? $available)), $available));
        $nature = (string) ($in['operation_nature'] ?? self::config('invoicing.operation_nature', 'services'));

        return [[
            'customer'         => $c,
            'price_basis'      => $basis,
            'lines'            => $lines,
            'due_days'         => max(0, $dueDays),
            'due_date'         => $dueDate,
            'methods'          => $methods,
            'comment'          => mb_substr(trim((string) ($in['comment'] ?? '')), 0, 4000),
            'operation_nature' => isset(self::NATURES[$nature]) ? $nature : 'services',
        ], $errors];
    }

    /**
     * Crée (ou met à jour, tant qu'aucun document n'est émis) une commande manuelle au statut « Brouillon ».
     *
     * @return array{reference: ?string, errors: array<string, string>}
     */
    public static function saveDraft(array $input, string $user, ?string $reference = null): array
    {
        [$clean, $errors] = self::validate($input);
        if ($reference !== null) {
            $order = OrderManager::getOrder($reference);
            if ($order === null || ($order['source'] ?? 'shop') !== 'manual') {
                return ['reference' => null, 'errors' => ['reference' => 'Commande manuelle introuvable.']];
            }
            if (self::documentsForOrder($reference, true) !== []) {
                return ['reference' => null, 'errors' => ['reference' => 'Un document a déjà été émis : la commande ne se modifie plus (émettre un avoir).']];
            }
        }
        if ($errors) {
            return ['reference' => null, 'errors' => $errors];
        }

        $amounts = self::amountsFromLines($clean['lines'], $clean['price_basis']);
        $db = InventoryManager::getDatabase();
        $reference ??= 'CMD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $customer = self::customerAsOrderData($clean['customer']);
        $payload = array_diff_key($clean, ['lines' => 1]) + ['created_by' => $user];

        $db->beginTransaction();
        try {
            $exists = $db->prepare('SELECT 1 FROM "order" WHERE order_reference = :r');
            $exists->execute([':r' => $reference]);
            $isUpdate = $exists->fetchColumn() !== false;
            $exists->closeCursor();
            if ($isUpdate) {
                $db->prepare('UPDATE "order" SET customer_data = :c, billing_data = :b, total_amount = :t, total_cents = :tc, currency = :cur,
                        shipping_ht_cents = 0, shipping_ttc_cents = 0, shipping_taxes = :st, payload = :p WHERE order_reference = :r')
                    ->execute([':c' => json_encode($customer, JSON_UNESCAPED_UNICODE), ':b' => json_encode(self::billingFromCustomer($clean['customer']), JSON_UNESCAPED_UNICODE),
                        ':t' => $amounts['total_ttc_cents'] / 100, ':tc' => $amounts['total_ttc_cents'], ':cur' => $amounts['currency'],
                        ':st' => '[]', ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE), ':r' => $reference]);
                $db->prepare('DELETE FROM order_item WHERE order_reference = :r')->execute([':r' => $reference]);
            } else {
                $db->prepare('INSERT INTO "order" (order_reference, customer_data, shipping_data, billing_data, total_amount, status, source, currency,
                        total_cents, shipping_ht_cents, shipping_ttc_cents, shipping_taxes, payload)
                        VALUES (:r, :c, :s, :b, :t, \'draft\', \'manual\', :cur, :tc, 0, 0, :st, :p)')
                    ->execute([':r' => $reference, ':c' => json_encode($customer, JSON_UNESCAPED_UNICODE), ':s' => '{}',
                        ':b' => json_encode(self::billingFromCustomer($clean['customer']), JSON_UNESCAPED_UNICODE), ':t' => $amounts['total_ttc_cents'] / 100,
                        ':cur' => $amounts['currency'], ':tc' => $amounts['total_ttc_cents'], ':st' => '[]', ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                $db->prepare('INSERT INTO order_status_history (order_reference, old_status, new_status, changed_by) VALUES (:r, NULL, \'draft\', :by)')
                    ->execute([':r' => $reference, ':by' => $user]);
            }
            $item = $db->prepare('INSERT INTO order_item (order_reference, product_route, title, price, quantity, unit_ht_cents, unit_ttc_cents, tax_code,
                    tax_bp, product_type, line_id, options, taxes) VALUES (:r, \'\', :t, :p, :q, :ht, :ttc, :code, :bp, \'manual\', :lid, \'{}\', :taxes)');
            foreach ($amounts['lines'] as $i => $l) {
                $item->execute([':r' => $reference, ':t' => $l['title'], ':p' => $l['unit_ttc_cents'] / 100, ':q' => $l['quantity'],
                    ':ht' => $l['unit_ht_cents'], ':ttc' => $l['unit_ttc_cents'], ':code' => $l['tax_code'], ':bp' => $l['tax_bp'],
                    ':lid' => 'manual-' . ($i + 1), ':taxes' => json_encode($l['taxes'], JSON_UNESCAPED_UNICODE)]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['reference' => $reference, 'errors' => []];
    }

    /** Saisie d'une commande manuelle telle qu'enregistrée (pour la rouvrir dans le formulaire). */
    public static function draftInput(array $order, array $items): array
    {
        $payload = json_decode((string) ($order['payload'] ?? '{}'), true) ?: [];
        $basis = (string) ($payload['price_basis'] ?? 'ttc');
        $lines = [];
        foreach ($items as $i) {
            $cents = (int) ($basis === 'ht' ? $i['unit_ht_cents'] : $i['unit_ttc_cents']);
            $lines[] = ['title' => (string) $i['title'], 'quantity' => (int) $i['quantity'], 'unit_price' => number_format($cents / 100, 2, '.', ''),
                'tax_type' => (string) $i['tax_code']];
        }

        return $payload + ['lines' => $lines, 'customer' => json_decode((string) ($order['customer_data'] ?? '{}'), true) ?: []];
    }

    /**
     * Lignes saisies → montants (centimes) : taxe du type choisi à l'adresse de la boutique (franchise en base comprise),
     * TTC calculé depuis le HT (organisation) ou HT depuis le TTC (particulier).
     */
    public static function amountsFromLines(array $lines, string $basis): array
    {
        $currency = \Grav\Plugin\SimpleShop\Product\Catalog::currency();
        $built = [];
        foreach ($lines as $l) {
            $rate = TaxResolver::forRate((string) ($l['tax_type'] ?? ''));
            $price = Money::of((int) $l['unit_price_cents'], $currency);
            [$ht, $ttc] = $basis === 'ht' ? [$price->amount, $rate->addTo($price)->amount] : [$rate->removeFrom($price)->amount, $price->amount];
            $built[] = ['title' => $l['title'], 'quantity' => (int) $l['quantity'], 'unit_ht_cents' => $ht, 'unit_ttc_cents' => $ttc,
                'tax_code' => $rate->code, 'tax_bp' => $rate->basisPoints, 'taxes' => array_map(static fn ($c) => $c->jsonSerialize(), $rate->components)];
        }

        return OrderAmounts::fromLines($built, $currency, $basis === 'ht');
    }

    // ------------------------------------------------------------------
    // Émission
    // ------------------------------------------------------------------

    /**
     * Émet un document pour une commande manuelle.
     *
     * @param array $opts `deposit` : ['mode' => 'percent'|'amount', 'value' => '30' | '500.00'] (facture d'acompte) ;
     *                    `issued_by` : compte admin.
     *
     * @return array{document: ?array, errors: array<string, string>}
     */
    public static function issue(string $reference, string $kind, array $opts = []): array
    {
        if (!in_array($kind, ['invoice', 'deposit', 'payment_request'], true)) {
            return ['document' => null, 'errors' => ['kind' => 'Type de document inconnu.']];
        }
        $order = OrderManager::getOrder($reference);
        if ($order === null || ($order['source'] ?? 'shop') !== 'manual') {
            return ['document' => null, 'errors' => ['reference' => 'Commande manuelle introuvable.']];
        }
        $items = self::orderItems($reference);
        $payload = json_decode((string) ($order['payload'] ?? '{}'), true) ?: [];
        $basis = (string) ($payload['price_basis'] ?? 'ttc');
        $existing = self::documentsForOrder($reference, true);
        $byKind = static fn (string $k) => array_values(array_filter($existing, static fn ($d) => $d['kind'] === $k));

        if ($byKind('invoice') !== []) {
            return ['document' => null, 'errors' => ['kind' => 'La facture de cette commande est déjà émise (' . $byKind('invoice')[0]['invoice_number'] . ').']];
        }
        if ($kind === 'payment_request' && array_filter($byKind('payment_request'), static fn ($d) => self::settlement($d)['state'] !== 'paid') !== []) {
            return ['document' => null, 'errors' => ['kind' => 'Une demande de paiement de cette commande est déjà en attente.']];
        }

        $orderAmounts = OrderAmounts::fromStored($order, $items, \Grav\Plugin\SimpleShop\Product\Catalog::taxRate());
        if ($basis === 'ht') {
            $orderAmounts['total_ttc_cents'] = OrderAmounts::invoiceTotals($orderAmounts)['grand_total_cents'];
        }
        $deposits = $byKind('deposit');
        $depositsTotal = array_sum(array_map(static fn ($d) => (int) $d['total_cents'], $deposits));
        $deductions = [];
        $amounts = $orderAmounts;

        if ($kind === 'deposit') {
            $value = (string) ($opts['deposit']['value'] ?? '');
            $mode = ($opts['deposit']['mode'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            $base = $basis === 'ht' ? OrderAmounts::invoiceTotals($orderAmounts)['line_total_ht_cents'] : $orderAmounts['total_ttc_cents'];
            if ($mode === 'percent') {
                $pct = (float) str_replace(',', '.', $value);
                if (!is_numeric(str_replace(',', '.', $value)) || $pct <= 0 || $pct >= 100) {
                    return ['document' => null, 'errors' => ['deposit' => 'Pourcentage de 1 à 99.']];
                }
                $share = (int) round($base * $pct / 100);
                $label = rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',') . ' %';
            } else {
                $share = self::parseAmount($value);
                if ($share === null || $share <= 0 || $share >= $base) {
                    return ['document' => null, 'errors' => ['deposit' => 'Montant positif, inférieur au total de la commande (' . ($basis === 'ht' ? 'HT' : 'TTC') . ').']];
                }
                $label = number_format($share / 100, 2, ',', ' ') . ' ' . $orderAmounts['currency'] . ($basis === 'ht' ? ' HT' : '');
            }
            $amounts = self::depositAmounts($orderAmounts, $share, $base, $basis, 'Acompte de ' . $label . ' — commande ' . $reference);
        } elseif ($deposits !== []) {
            // Facture finale, ou demande de paiement du solde (la facture émise à son règlement reprendra ces déductions).
            foreach ($deposits as $d) {
                $deductions[] = ['label' => "Acompte facturé {$d['invoice_number']} du " . date('d/m/Y', strtotime((string) $d['issued_at']) ?: time()),
                    'cents' => (int) $d['total_cents'], 'number' => $d['invoice_number']];
            }
        }

        $totals = OrderAmounts::invoiceTotals($amounts);
        if (!$totals['en16931'] && $kind !== 'payment_request') {
            return ['document' => null, 'errors' => ['lines' => 'Plusieurs taxes sur une même ligne : non exprimable en facture électronique.']];
        }
        $total = $amounts['total_ttc_cents'];
        $due = $total - $depositsTotal * ($kind === 'invoice' ? 1 : 0);
        if ($kind === 'payment_request') {
            // Ce qui reste à payer sur la commande (acomptes facturés déduits).
            $due = $total - $depositsTotal;
            if ($due <= 0) {
                return ['document' => null, 'errors' => ['kind' => 'Rien à demander : les acomptes couvrent la commande.']];
            }
        }

        $issued = gmdate('Y-m-d H:i:s');
        $dueDate = (string) ($payload['due_date'] ?? '') !== '' ? (string) $payload['due_date']
            : gmdate('Y-m-d', time() + 86400 * (int) ($payload['due_days'] ?? self::config('invoicing.payment_terms_days', 30)));
        $frozen = [
            'customer'         => json_decode((string) ($order['customer_data'] ?? '{}'), true) ?: [],
            'price_basis'      => $basis,
            'operation_nature' => (string) ($payload['operation_nature'] ?? 'services'),
            'methods'          => array_values((array) ($payload['methods'] ?? [])),
            'comment'          => (string) ($payload['comment'] ?? ''),
            'due_date'         => $dueDate,
            'amounts'          => $amounts,
            'deductions'       => $deductions,
            'prepaid_cents'    => $kind === 'deposit' ? 0 : $depositsTotal,
            'issued_by'        => (string) ($opts['issued_by'] ?? ''),
            'amount_policy'    => 'remaining',   // plus tard : next_installment, free (montant choisi par le client)
        ];

        $row = self::insertDocument($reference, $kind, $frozen, $total, $due, $issued, $dueDate);
        self::render($row);
        if (($order['status'] ?? '') === 'draft') {
            OrderManager::updateStatus($reference, 'pending', (string) ($opts['issued_by'] ?? '') ?: null);
        }

        return ['document' => self::find((int) $row['id']), 'errors' => []];
    }

    /** Lignes d'une facture d'acompte : la part demandée, répartie entre les taxes de la commande au prorata. */
    private static function depositAmounts(array $orderAmounts, int $share, int $base, string $basis, string $title): array
    {
        $groups = [];
        foreach ($orderAmounts['lines'] as $l) {
            $k = $l['tax_code'] . '|' . $l['tax_bp'];
            $groups[$k] ??= ['code' => $l['tax_code'], 'bp' => $l['tax_bp'], 'taxes' => $l['taxes'], 'weight' => 0];
            $groups[$k]['weight'] += $basis === 'ht' ? $l['line_ht_cents'] : $l['line_ttc_cents'];
        }
        $lines = [];
        $left = $share;
        $n = count($groups);
        $i = 0;
        foreach ($groups as $g) {
            $i++;
            $part = $i === $n ? $left : (int) round($share * $g['weight'] / max(1, $base));
            $left -= $part;
            $rate = new \Grav\Plugin\SimpleShop\Pricing\TaxRate($g['code'] ?: 'x', $g['bp']);
            $m = Money::of($part, $orderAmounts['currency']);
            [$ht, $ttc] = $basis === 'ht' ? [$part, $rate->addTo($m)->amount] : [$rate->removeFrom($m)->amount, $part];
            $lines[] = ['title' => $title . ($n > 1 ? ' (part ' . ($g['taxes'][0]['name'] ?? $g['code']) . ')' : ''), 'quantity' => 1,
                'unit_ht_cents' => $ht, 'unit_ttc_cents' => $ttc, 'tax_code' => $g['code'], 'tax_bp' => $g['bp'], 'taxes' => $g['taxes']];
        }

        return OrderAmounts::fromLines($lines, $orderAmounts['currency'], $basis === 'ht');
    }

    /** Enregistre le document (numéro réservé, jeton d'accès) ; réessaie en cas de collision de numéro. */
    private static function insertDocument(string $reference, string $kind, array $payload, int $total, int $due, string $issued, string $dueDate,
        ?int $sourceId = null): array
    {
        $db = InventoryManager::getDatabase();
        $customer = (array) ($payload['customer'] ?? []);
        $b2b = ($customer['type'] ?? '') === 'b2b';
        $status = $kind === 'payment_request' ? 'not_applicable'
            : ($b2b || InvoiceManager::isB2cEreportingActive() ? 'not_submitted' : 'disabled');
        $last = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = InvoiceManager::nextNumber($db, $kind === 'payment_request' ? 'DP' : 'FACT');
            try {
                $db->prepare('INSERT INTO invoice (order_reference, invoice_number, customer_type, ereporting_status, vat_rate, kind, total_cents, due_cents,
                        issued_at, due_date, access_token, source_invoice_id, payload)
                        VALUES (:r, :n, :t, :s, 0, :k, :tot, :due, :iss, :dd, :tok, :src, :p)')
                    ->execute([':r' => $reference, ':n' => $number, ':t' => $b2b ? 'b2b' : 'b2c', ':s' => $status, ':k' => $kind, ':tot' => $total,
                        ':due' => $due, ':iss' => $issued, ':dd' => $dueDate, ':tok' => bin2hex(random_bytes(16)), ':src' => $sourceId,
                        ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
                $row = self::find((int) $db->lastInsertId());
                if ($kind !== 'payment_request') {
                    InvoiceManager::fireInvoiceStatusChangedEvent(Grav::instance(), $reference, null, $status);
                }

                return $row;
            } catch (\PDOException $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * Après un paiement : une demande de paiement entièrement réglée donne sa facture (déjà payée), une seule fois.
     *
     * @return array|null La facture émise, s'il y en a une.
     */
    public static function afterPayment(int $documentId): ?array
    {
        $doc = self::find($documentId);
        if ($doc === null || $doc['kind'] !== 'payment_request' || self::settlement($doc)['state'] !== 'paid') {
            return null;
        }
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare('SELECT id FROM invoice WHERE source_invoice_id = :id');
        $stmt->execute([':id' => $documentId]);
        $done = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($done !== false) {
            return null;
        }
        $p = $doc['payload'];
        $paid = self::settlement($doc)['paid_cents'];
        $p['prepaid_cents'] = (int) ($p['prepaid_cents'] ?? 0) + $paid;
        $p['paid_at_issue'] = true;
        $p['source_number'] = $doc['invoice_number'];
        $row = self::insertDocument($doc['order_reference'], 'invoice', $p, (int) $doc['total_cents'], (int) $doc['due_cents'], gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d'), $documentId);
        self::render($row);

        return self::find((int) $row['id']);
    }

    // ------------------------------------------------------------------
    // Lecture, état de règlement
    // ------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM invoice WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ? self::cast($row) : null;
    }

    /** Document par jeton d'accès (page publique `/document/<jeton>`) ; comparaison à temps constant. */
    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM invoice WHERE access_token = :t');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row && hash_equals((string) $row['access_token'], $token) ? self::cast($row) : null;
    }

    /** Documents d'une commande, dans l'ordre d'émission. */
    public static function documentsForOrder(string $reference, bool $raw = false): array
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM invoice WHERE order_reference = :r ORDER BY id ASC');
        $stmt->execute([':r' => $reference]);

        return array_map([self::class, 'cast'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Lignes `order_item` d'une commande. */
    public static function orderItems(string $reference): array
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM order_item WHERE order_reference = :r ORDER BY id ASC');
        $stmt->execute([':r' => $reference]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * État de règlement d'un document : montant à régler sur ce document (acomptes facturés déduits) et paiements reçus
     * sur lui — et sur la demande de paiement dont il est issu. Une facture de la boutique (sans montant enregistré) suit
     * le règlement de sa commande.
     */
    public static function settlement(array $doc): array
    {
        if ($doc['due_cents'] === null) {
            $order = OrderManager::getOrder((string) $doc['order_reference']);

            return $order ? PaymentLedger::orderSettlement($order) : PaymentLedger::settle(0, []);
        }
        $payments = PaymentLedger::forInvoice((int) $doc['id']);
        if (!empty($doc['source_invoice_id'])) {
            $payments = array_merge($payments, PaymentLedger::forInvoice((int) $doc['source_invoice_id']));
        }

        return PaymentLedger::settle((int) $doc['due_cents'], $payments) + ['currency' => (string) ($doc['payload']['amounts']['currency'] ?? 'EUR')];
    }

    /**
     * Liste des documents (onglet Factures) : filtres `kind`, `state` (état de règlement, `overdue` = en retard), `q`.
     *
     * @return array{items: list<array>, total: int}
     */
    public static function search(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $db = InventoryManager::getDatabase();
        $where = [];
        $params = [];
        if (isset(self::KINDS[(string) ($filters['kind'] ?? '')])) {
            $where[] = "COALESCE(i.kind, 'invoice') = :k";
            $params[':k'] = $filters['kind'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(i.invoice_number LIKE :q OR i.order_reference LIKE :q OR o.customer_data LIKE :q)';
            $params[':q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        }
        $stmt = $db->prepare('SELECT i.* FROM invoice i JOIN "order" o ON o.order_reference = i.order_reference'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.id DESC');
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $state = (string) ($filters['state'] ?? '');
        $out = [];
        foreach ($rows as $r) {
            $r['settlement'] = self::settlement($r);
            $r['overdue'] = in_array($r['settlement']['state'], ['unpaid', 'partial'], true) && $r['due_date'] !== null && $r['due_date'] < gmdate('Y-m-d');
            if ($state === 'overdue' ? !$r['overdue'] : ($state !== '' && $r['settlement']['state'] !== $state)) {
                continue;
            }
            $out[] = $r;
        }
        $total = count($out);

        return ['items' => $limit > 0 ? array_slice($out, $offset, $limit) : $out, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // PDF / Factur-X
    // ------------------------------------------------------------------

    /**
     * Génère et enregistre le PDF d'un document (et son XML Factur-X), une fois, à l'émission.
     *
     * @return string|null Chemin du PDF.
     */
    public static function render(array $doc): ?string
    {
        $doc = isset($doc['payload']) && is_array($doc['payload']) ? $doc : self::find((int) $doc['id']);
        $dir = self::storageDir();
        $path = $dir . '/' . $doc['invoice_number'] . '.pdf';
        try {
            $parts = self::invoiceFor($doc)->buildParts();
        } catch (\Throwable $e) {
            InvoiceLogger::error('Génération du document ' . $doc['invoice_number'] . ' impossible : ' . $e->getMessage());

            return null;
        }
        file_put_contents($path, $parts['pdf']);
        if ($parts['xml'] !== null) {
            file_put_contents($dir . '/' . $doc['invoice_number'] . '.xml', $parts['xml']);
        }
        InventoryManager::getDatabase()->prepare('UPDATE invoice SET pdf_path = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':p' => $path, ':id' => $doc['id']]);

        return $path;
    }

    /**
     * Chemin du PDF : `original` (émis), ou `paid` (copie acquittée : même XML, mention « ACQUITTÉE le … » ; seulement
     * quand le document est réglé).
     */
    public static function pdfPath(array $doc, string $copy = 'original'): ?string
    {
        $dir = self::storageDir();
        $original = $dir . '/' . $doc['invoice_number'] . '.pdf';
        if (!is_file($original) && self::render($doc) === null) {
            return null;
        }
        if ($copy !== 'paid') {
            return $original;
        }
        $st = self::settlement($doc);
        if ($st['state'] !== 'paid') {
            return null;
        }
        $payments = PaymentLedger::forInvoice((int) $doc['id']);
        if (!empty($doc['source_invoice_id'])) {
            $payments = array_merge($payments, PaymentLedger::forInvoice((int) $doc['source_invoice_id']));
        }
        $lastPay = end($payments) ?: null;
        $stamp = 'ACQUITTÉE le ' . date('d/m/Y', strtotime((string) ($lastPay['paid_at'] ?? 'now')) ?: time())
            . ($lastPay ? ' — ' . PaymentLedger::methodLabel((string) $lastPay['method']) : '');
        $paidPath = $dir . '/' . $doc['invoice_number'] . '-acquittee.pdf';
        $xmlFile = $dir . '/' . $doc['invoice_number'] . '.xml';
        $xml = is_file($xmlFile) ? (string) file_get_contents($xmlFile) : null;
        try {
            file_put_contents($paidPath, self::invoiceFor($doc, ['stamp' => $stamp])->withStoredXml($xml));
        } catch (\Throwable $e) {
            InvoiceLogger::error('Copie acquittée de ' . $doc['invoice_number'] . ' impossible : ' . $e->getMessage());

            return null;
        }

        return $paidPath;
    }

    /** Aperçu (PDF sans valeur, « APERÇU ») du document qu'on émettrait, sans rien enregistrer. */
    public static function preview(string $reference, string $kind = 'invoice'): ?string
    {
        $order = OrderManager::getOrder($reference);
        if ($order === null) {
            return null;
        }
        $payload = json_decode((string) ($order['payload'] ?? '{}'), true) ?: [];
        $basis = (string) ($payload['price_basis'] ?? 'ttc');
        $amounts = OrderAmounts::fromStored($order, self::orderItems($reference), \Grav\Plugin\SimpleShop\Product\Catalog::taxRate());
        if ($basis === 'ht') {
            $amounts['total_ttc_cents'] = OrderAmounts::invoiceTotals($amounts)['grand_total_cents'];
        }
        $fake = [
            'id' => 0, 'order_reference' => $reference, 'invoice_number' => '(aperçu)', 'kind' => isset(self::TITLES[$kind]) ? $kind : 'invoice',
            'total_cents' => $amounts['total_ttc_cents'], 'due_cents' => $amounts['total_ttc_cents'], 'issued_at' => gmdate('Y-m-d H:i:s'),
            'due_date' => gmdate('Y-m-d', time() + 86400 * (int) ($payload['due_days'] ?? 30)), 'access_token' => '', 'source_invoice_id' => null,
            'payload' => ['customer' => json_decode((string) ($order['customer_data'] ?? '{}'), true) ?: [], 'price_basis' => $basis,
                'operation_nature' => $payload['operation_nature'] ?? 'services', 'methods' => $payload['methods'] ?? [], 'amounts' => $amounts,
                'deductions' => [], 'prepaid_cents' => 0, 'due_date' => (string) ($payload['due_date'] ?? '')],
        ];

        return self::invoiceFor($fake, ['watermark' => 'APERÇU — sans valeur', 'no_xml' => true])->build();
    }

    /** Construit l'objet Invoice d'un document (données figées à l'émission). */
    public static function invoiceFor(array $doc, array $extra = []): Invoice
    {
        $order = OrderManager::getOrder((string) $doc['order_reference']) ?? ['order_reference' => $doc['order_reference']];

        return new Invoice($order, [], ['invoice_number' => $doc['invoice_number']], InvoiceManager::getSellerData(), $extra + self::documentData($doc));
    }

    /** Données d'affichage et Factur-X d'un document (voir FacturXXmlBuilder::documentDefaults() et Invoice). */
    public static function documentData(array $doc): array
    {
        $p = (array) $doc['payload'];
        $kind = (string) $doc['kind'];
        $c = (array) ($p['customer'] ?? []);
        $b2b = ($c['type'] ?? '') === 'b2b';
        $nature = self::NATURES[(string) ($p['operation_nature'] ?? 'services')] ?? 'S';
        $paidAtIssue = !empty($p['paid_at_issue']);
        $issue = date('Ymd', strtotime((string) $doc['issued_at']) ?: time());
        $dueYmd = str_replace('-', '', (string) ($doc['due_date'] ?? '')) ?: $issue;
        $amounts = (array) $p['amounts'];
        $prepaid = (int) ($p['prepaid_cents'] ?? 0);
        $cur = (string) ($amounts['currency'] ?? 'EUR');
        $fmtDate = static fn (string $ymd): string => substr($ymd, 6, 2) . '/' . substr($ymd, 4, 2) . '/' . substr($ymd, 0, 4);
        $fmtMoney = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' ' . $cur;

        // Moyens de paiement (BG-16) et texte des conditions.
        $means = [];
        $meansText = [];
        $account = BankTransferGateway::account();
        foreach ((array) ($p['methods'] ?? []) as $m) {
            if ($m === 'bank_transfer' && $account['iban'] !== '') {
                $means[] = ['code' => '30', 'iban' => $account['iban'], 'bic' => $account['bic'], 'holder' => $account['holder']];
                $meansText[] = 'virement (IBAN ' . BankTransferGateway::formatIban($account['iban']) . ($account['bic'] !== '' ? ', BIC ' . $account['bic'] : '')
                    . ($account['holder'] !== '' ? ', titulaire ' . $account['holder'] : '') . ', référence ' . $doc['invoice_number'] . ')';
            } elseif (in_array($m, ['stripe', 'card'], true)) {
                $means[] = ['code' => '48'];
                $meansText[] = 'carte bancaire en ligne (lien de paiement)';
            }
        }

        $payable = $paidAtIssue ? 0 : null;
        $terms = $paidAtIssue ? 'Facture réglée (' . ($p['source_number'] ?? '') . ').'
            : ($kind === 'credit_note' ? '' : 'À régler au plus tard le ' . $fmtDate($dueYmd) . '.');
        $config = static fn (string $k, $d) => self::config($k, $d);
        $notes = $paidAtIssue ? (array) (InvoiceManager::getSellerData()['notes'] ?? []) : [
            'AAB' => (string) $config('invoicing.discount', "Pas d'escompte pour paiement anticipé."),
            'PMD' => $b2b ? (string) $config('invoicing.late_penalty', "Pénalités de retard : trois fois le taux d'intérêt légal en vigueur.")
                : 'Pénalités de retard : sans objet (client particulier).',
            'PMT' => $b2b ? 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : ' . (int) $config('invoicing.recovery_fee', 40) . ' €.'
                : 'Indemnité forfaitaire pour frais de recouvrement : sans objet (client particulier).',
        ];
        $free = [];
        if ($config('invoicing.vat_on_debits', false)) {
            $free[] = 'Option pour le paiement de la taxe d’après les débits.';
        }
        $free[] = 'Nature de l’opération : ' . mb_strtolower(self::NATURE_LABELS[(string) ($p['operation_nature'] ?? 'services')] ?? 'prestations de services') . '.';
        foreach ((array) ($p['deductions'] ?? []) as $ded) {
            $free[] = $ded['label'] . ' : ' . $fmtMoney((int) $ded['cents']) . ' déduit.';
        }
        if (!empty($p['source_number']) && $kind === 'invoice') {
            $free[] = 'Émise au règlement de la demande de paiement ' . $p['source_number'] . '.';
        }

        $buyerName = $b2b ? (string) ($c['company'] ?? '') : trim(((string) ($c['firstname'] ?? '')) . ' ' . ((string) ($c['lastname'] ?? '')));
        $footer = [];
        if ($kind !== 'payment_request') {
            $footer[] = 'Facture électronique au format Factur-X (norme EN 16931). Client ' . ($b2b ? 'professionnel (B2B)' : 'particulier (B2C)') . '.';
        } else {
            $footer[] = 'Demande de paiement : ce document n’est pas une facture ; la facture sera émise à réception du règlement.';
        }
        if ($terms !== '') {
            $footer[] = 'Conditions de paiement : ' . $terms;
        }
        if ($meansText !== [] && !$paidAtIssue) {
            $footer[] = 'Moyens de paiement : ' . implode(' ; ', $meansText) . '.';
        }
        foreach ($notes as $n) {
            $footer[] = (string) $n;
        }
        foreach ($free as $n) {
            $footer[] = $n;
        }
        $legal = trim((string) $config('invoicing.legal_mention', ''));
        if ($legal !== '') {
            $footer[] = $legal;
        }

        return [
            'type_code'     => self::TYPE_CODES[$kind] ?? '380',
            'title'         => self::TITLES[$kind] ?? 'FACTURE',
            'issue_date'    => $issue,
            'due_date'      => $paidAtIssue ? $issue : $dueYmd,
            'payment_terms' => $terms,
            'prepaid_cents' => $prepaid,
            'payment_means' => $paidAtIssue ? [] : $means,
            'billing_mode'  => $nature . ($paidAtIssue ? '2' : ((array) ($p['deductions'] ?? []) !== [] ? '4' : '1')),
            'notes'         => $notes,
            'free_notes'    => $free,
            'buyer'         => [
                'name' => $buyerName, 'contact' => $b2b ? trim(((string) ($c['firstname'] ?? '')) . ' ' . ((string) ($c['lastname'] ?? ''))) : '',
                'street' => (string) ($c['street'] ?? ''), 'complement' => (string) ($c['address-complement'] ?? ''),
                'postal_code' => (string) ($c['postal-code'] ?? ''), 'city' => (string) ($c['city'] ?? ''), 'country' => (string) (($c['country'] ?? '') ?: 'FR'),
                'siren' => $b2b ? (string) ($c['siren'] ?? '') : '', 'vat' => $b2b ? (string) ($c['vat'] ?? '') : '', 'email' => (string) ($c['email'] ?? ''),
            ],
            'prices_ht'     => ($p['price_basis'] ?? '') === 'ht',
            'info_lines'    => array_filter([
                $kind !== 'credit_note' && !$paidAtIssue ? 'Échéance : ' . $fmtDate($dueYmd) : null,
            ]),
            'deductions'    => array_map(static fn ($d) => ['label' => $d['label'], 'cents' => $d['cents']], (array) ($p['deductions'] ?? [])),
            'footer'        => $footer,
            'amounts'       => $amounts,
            'no_xml'        => $kind === 'payment_request',
        ];
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /** « 120.50 » / « 120,5 » → 12050 centimes ; null si invalide ou négatif. */
    public static function parseAmount(string $value): ?int
    {
        $v = trim(str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $value));
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v)) {
            return null;
        }
        [$u, $d] = array_pad(explode('.', $v), 2, '0');

        return (int) $u * 100 + (int) str_pad(substr($d, 0, 2), 2, '0');
    }

    private static function customerAsOrderData(array $c): array
    {
        return array_filter([
            'firstname' => $c['firstname'], 'lastname' => $c['lastname'], 'email' => $c['email'], 'tel' => $c['tel'], 'street' => $c['street'],
            'address-complement' => $c['address-complement'], 'postal-code' => $c['postal-code'], 'city' => $c['city'], 'country' => $c['country'],
            'company' => $c['company'], 'siren' => $c['siren'], 'vat' => $c['vat'], 'type' => $c['type'],
        ], static fn ($v) => $v !== '');
    }

    private static function billingFromCustomer(array $c): array
    {
        return array_filter([
            'name' => $c['type'] === 'b2b' ? $c['company'] : trim($c['firstname'] . ' ' . $c['lastname']),
            'street' => $c['street'], 'address-complement' => $c['address-complement'], 'postal-code' => $c['postal-code'], 'city' => $c['city'],
        ], static fn ($v) => $v !== '');
    }

    private static function storageDir(): string
    {
        $dir = Grav::instance()['locator']->findResource('user://data/simple-shop/invoices', true, true);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function config(string $key, $default = null)
    {
        return Grav::instance()['config']->get('plugins.simple-shop.' . $key, $default);
    }

    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['kind'] = (string) (($row['kind'] ?? '') ?: 'invoice');
        $row['total_cents'] = $row['total_cents'] !== null ? (int) $row['total_cents'] : null;
        $row['due_cents'] = $row['due_cents'] !== null ? (int) $row['due_cents'] : null;
        $row['source_invoice_id'] = $row['source_invoice_id'] !== null ? (int) $row['source_invoice_id'] : null;
        $row['payload'] = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
        $row['kind_label'] = self::KINDS[$row['kind']] ?? $row['kind'];

        return $row;
    }
}
