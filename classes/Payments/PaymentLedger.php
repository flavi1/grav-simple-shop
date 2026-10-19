<?php

namespace Grav\Plugin\SimpleShop\Payments;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use RocketTheme\Toolbox\Event\Event;

/**
 * Paiements (table `payment`) et état de règlement.
 *
 * Un paiement = de l'argent reçu (montant positif) ou rendu au client (négatif : remboursement), rattaché à une commande
 * et, s'il y a lieu, au document payé (facture, acompte, demande de paiement). L'état de règlement d'une commande ou d'un
 * document n'est jamais saisi : il se calcule à partir des paiements (comme Dolibarr, Odoo ou Invoice Ninja). « Payée »
 * n'est donc plus un statut de commande (voir docs/CONCEPTION-FACTURATION.md).
 *
 * Idempotence : `external_ref` (ex. identifiant de paiement Stripe) est unique ; enregistrer deux fois le même paiement
 * (retour du client + webhook, rejeu d'un événement) renvoie le paiement existant.
 *
 * Événement : `onSimpleShopPaymentRecorded` (`payment` : la ligne enregistrée) après chaque nouveau paiement.
 */
final class PaymentLedger
{
    /** Libellés des moyens de paiement connus (les autres : identifiant de la passerelle). */
    public const METHOD_LABELS = [
        'stripe'        => 'Carte bancaire (Stripe)',
        'card'          => 'Carte bancaire',
        'test_gateway'  => 'Paiement de test',
        'bank_transfer' => 'Virement',
        'check'         => 'Chèque',
        'cash'          => 'Espèces',
        'other'         => 'Autre',
        'legacy'        => 'Paiement en ligne (repris)',
    ];

    /** Moyens qu'on peut enregistrer à la main dans l'administration (paiement reçu hors du site). */
    public const MANUAL_METHODS = ['bank_transfer', 'check', 'cash', 'card', 'other'];

    /** États de règlement (calculés). */
    public const STATES = [
        'none'     => 'Rien à régler',
        'unpaid'   => 'À régler',
        'partial'  => 'Partiellement réglée',
        'paid'     => 'Réglée',
        'overpaid' => 'Trop-perçu',
        'refunded' => 'Remboursée',
    ];

    public static function methodLabel(string $method): string
    {
        return self::METHOD_LABELS[$method] ?? $method;
    }

    public static function stateLabel(string $state): string
    {
        return self::STATES[$state] ?? $state;
    }

    /**
     * Enregistre un paiement.
     *
     * @param array{order_reference: string, amount_cents: int, method: string, invoice_id?: ?int, currency?: string,
     *              paid_at?: ?string, recorded_by?: ?string, external_ref?: ?string, note?: ?string} $p
     * @param \PDO|null $db        Connexion d'une transaction en cours (l'événement n'est alors pas déclenché : à l'appelant
     *                             de le faire après validation, voir {@see self::fireRecorded()}).
     *
     * @return array{payment: array, created: bool}
     */
    public static function record(array $p, ?\PDO $db = null): array
    {
        $order = trim((string) ($p['order_reference'] ?? ''));
        $amount = (int) ($p['amount_cents'] ?? 0);
        $method = (string) ($p['method'] ?? '');
        if ($order === '' || $amount === 0 || !preg_match('/^[a-z0-9_]{1,40}$/', $method)) {
            throw new \InvalidArgumentException('Paiement invalide (commande, montant non nul et moyen requis).');
        }
        $paidAt = self::normalizeDate($p['paid_at'] ?? null);
        $currency = strtoupper((string) ($p['currency'] ?? 'EUR'));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
        $ref = isset($p['external_ref']) && trim((string) $p['external_ref']) !== '' ? mb_substr(trim((string) $p['external_ref']), 0, 120) : null;
        $note = isset($p['note']) && trim((string) $p['note']) !== '' ? mb_substr(trim((string) $p['note']), 0, 500) : null;
        $by = isset($p['recorded_by']) && trim((string) $p['recorded_by']) !== '' ? mb_substr(trim((string) $p['recorded_by']), 0, 80) : null;
        $invoiceId = isset($p['invoice_id']) && (int) $p['invoice_id'] > 0 ? (int) $p['invoice_id'] : null;

        $own = $db === null;
        $db ??= InventoryManager::getDatabase();

        if ($ref !== null && ($existing = self::findByExternalRef($ref, $db)) !== null) {
            return ['payment' => $existing, 'created' => false];
        }

        try {
            $stmt = $db->prepare('INSERT INTO payment (order_reference, invoice_id, amount_cents, currency, method, paid_at, recorded_by, external_ref, note)
                VALUES (:o, :i, :a, :c, :m, :p, :b, :r, :n)');
            $stmt->execute([':o' => $order, ':i' => $invoiceId, ':a' => $amount, ':c' => $currency, ':m' => $method, ':p' => $paidAt,
                ':b' => $by, ':r' => $ref, ':n' => $note]);
        } catch (\PDOException $e) {
            // Course perdue contre un enregistrement simultané du même paiement (contrainte UNIQUE sur external_ref).
            if ($ref !== null && ($existing = self::findByExternalRef($ref, $db)) !== null) {
                return ['payment' => $existing, 'created' => false];
            }
            throw $e;
        }
        $payment = self::find((int) $db->lastInsertId(), $db);

        if ($own) {
            self::fireRecorded($payment);
        }

        return ['payment' => $payment, 'created' => true];
    }

    /** Déclenche `onSimpleShopPaymentRecorded` (non bloquant). */
    public static function fireRecorded(array $payment): void
    {
        try {
            Grav::instance()->fireEvent('onSimpleShopPaymentRecorded', new Event(['payment' => $payment]));
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('[SimpleShop] Écouteur de onSimpleShopPaymentRecorded en erreur : ' . $e->getMessage());
        }
    }

    public static function find(int $id, ?\PDO $db = null): ?array
    {
        $db ??= InventoryManager::getDatabase();
        $stmt = $db->prepare('SELECT * FROM payment WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ? self::cast($row) : null;
    }

    public static function findByExternalRef(string $ref, ?\PDO $db = null): ?array
    {
        $db ??= InventoryManager::getDatabase();
        $stmt = $db->prepare('SELECT * FROM payment WHERE external_ref = :r');
        $stmt->execute([':r' => $ref]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ? self::cast($row) : null;
    }

    /** Paiements d'une commande, du plus ancien au plus récent. */
    public static function forOrder(string $orderReference): array
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM payment WHERE order_reference = :o ORDER BY paid_at ASC, id ASC');
        $stmt->execute([':o' => $orderReference]);

        return array_map([self::class, 'cast'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Paiements rattachés à un document (table `invoice`). */
    public static function forInvoice(int $invoiceId): array
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT * FROM payment WHERE invoice_id = :i ORDER BY paid_at ASC, id ASC');
        $stmt->execute([':i' => $invoiceId]);

        return array_map([self::class, 'cast'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * État de règlement à partir du montant dû et des paiements.
     *
     * @param list<array{amount_cents: int}> $payments
     *
     * @return array{due_cents: int, paid_cents: int, refunded_cents: int, remaining_cents: int, state: string, label: string}
     */
    public static function settle(int $dueCents, array $payments): array
    {
        $received = 0;
        $refunded = 0;
        foreach ($payments as $p) {
            $a = (int) $p['amount_cents'];
            $a > 0 ? $received += $a : $refunded += -$a;
        }
        $paid = $received - $refunded;
        if ($refunded > 0 && $paid <= 0 && $received > 0) {
            $state = 'refunded';
        } elseif ($dueCents <= 0 && $paid <= 0) {
            $state = 'none';
        } elseif ($paid <= 0) {
            $state = 'unpaid';
        } elseif ($paid < $dueCents) {
            $state = 'partial';
        } elseif ($paid === $dueCents) {
            $state = 'paid';
        } else {
            $state = 'overpaid';
        }

        return [
            'due_cents'       => $dueCents,
            'paid_cents'      => $paid,
            'refunded_cents'  => $refunded,
            'remaining_cents' => max(0, $dueCents - $paid),
            'state'           => $state,
            'label'           => self::stateLabel($state),
        ];
    }

    /** Montant dû d'une commande (centimes) : total enregistré, ou ancien total décimal. */
    public static function orderDue(array $order): int
    {
        if (isset($order['total_cents']) && $order['total_cents'] !== null && $order['total_cents'] !== '') {
            return (int) $order['total_cents'];
        }

        return (int) round(((float) ($order['total_amount'] ?? 0)) * 100);
    }

    /** État de règlement d'une commande (tous ses paiements). */
    public static function orderSettlement(array $order, ?array $payments = null): array
    {
        $payments ??= self::forOrder((string) $order['order_reference']);

        return self::settle(self::orderDue($order), $payments) + ['currency' => (string) (($order['currency'] ?? '') ?: 'EUR')];
    }

    /**
     * Reprise des commandes d'avant les paiements (une seule fois, idempotente) : chaque commande au statut « paid » (payée
     * en ligne à sa création) reçoit le paiement correspondant (montant de la commande, référence de `order_payment`), puis
     * passe au statut « processing » (« En préparation »).
     */
    public static function migrateLegacyPaidOrders(\PDO $db): void
    {
        $rows = $db->query('SELECT o.order_reference, o.total_cents, o.total_amount, o.currency, o.created_at,
                (SELECT payment_reference FROM order_payment op WHERE op.order_reference = o.order_reference LIMIT 1) AS ref
            FROM "order" o WHERE o.status = \'paid\'')->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }
        $has = $db->prepare('SELECT COUNT(*) FROM payment WHERE order_reference = :o');
        $db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $has->execute([':o' => $row['order_reference']]);
                $count = (int) $has->fetchColumn();
                $has->closeCursor();
                $due = self::orderDue($row);
                if ($count === 0 && $due > 0) {
                    $ref = (string) ($row['ref'] ?? '');
                    self::record([
                        'order_reference' => $row['order_reference'],
                        'amount_cents'    => $due,
                        'currency'        => $row['currency'] ?: 'EUR',
                        'method'          => str_starts_with($ref, 'pi_') ? 'stripe' : 'legacy',
                        'paid_at'         => $row['created_at'],
                        'recorded_by'     => 'reprise',
                        'external_ref'    => $ref !== '' ? $ref : null,
                        'note'            => 'Paiement de la commande en ligne (repris du statut « Payée »).',
                    ], $db);
                }
                $db->prepare('UPDATE "order" SET status = \'processing\' WHERE order_reference = :o')->execute([':o' => $row['order_reference']]);
                $db->prepare('INSERT INTO order_status_history (order_reference, old_status, new_status, changed_by) VALUES (:o, \'paid\', \'processing\', \'reprise\')')
                    ->execute([':o' => $row['order_reference']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Date « AAAA-MM-JJ » ou « AAAA-MM-JJ HH:MM[:SS] » (UTC), maintenant par défaut. */
    private static function normalizeDate($value): string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 12:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return str_replace('T', ' ', strlen($value) === 16 ? $value . ':00' : $value);
        }

        return gmdate('Y-m-d H:i:s');
    }

    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['amount_cents'] = (int) $row['amount_cents'];
        $row['invoice_id'] = $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null;
        $row['method_label'] = self::methodLabel((string) $row['method']);

        return $row;
    }
}
