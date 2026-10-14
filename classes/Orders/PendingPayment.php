<?php

namespace Grav\Plugin\SimpleShop\Orders;

use Grav\Plugin\SimpleShop\Inventory\InventoryManager;

/**
 * Instantanés de commande en attente de paiement.
 *
 * Quand un paiement démarre, la passerelle enregistre ici ce qui permet de
 * créer la commande SANS session : identifiant du panier, client, livraison,
 * facturation et montant attendu (en centimes). Seul l'identifiant de
 * l'instantané est confié à la passerelle (métadonnées Stripe) ; un webhook
 * peut ainsi finaliser une commande dont le client a fermé son navigateur
 * avant le retour sur le site (voir {@see \Grav\Plugin\SimpleShop\Services\OrderFinalizer}).
 *
 * Seuls les champs nécessaires sont conservés (liste blanche) : jamais le
 * contenu complet de la session.
 */
class PendingPayment
{
    /** Clés de la commande en cours conservées dans l'instantané. */
    private const KEYS = ['cart_id', 'customer', 'shipping', 'billing'];

    /** Durée de conservation, en jours. */
    private const RETENTION_DAYS = 30;

    /**
     * Enregistre (ou met à jour) l'instantané d'une commande en cours.
     *
     * @param array       $orderData   Données de la commande en cours (session).
     * @param int         $amountCents Montant à régler, en centimes.
     * @param string|null $snapshotId  Identifiant existant à mettre à jour ; sinon un nouveau est créé.
     *
     * @return string L'identifiant de l'instantané.
     */
    public static function save(array $orderData, int $amountCents, ?string $snapshotId = null): string
    {
        $snapshot = [];
        foreach (self::KEYS as $key) {
            $value = $orderData[$key] ?? null;
            $snapshot[$key] = is_object($value) ? (array) $value : $value;
        }

        $snapshotId = ($snapshotId !== null && preg_match('/^[a-f0-9]{32}$/', $snapshotId))
            ? $snapshotId
            : bin2hex(random_bytes(16));

        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("
            INSERT INTO pending_payment (snapshot_id, order_data, amount_cents)
            VALUES (:id, :data, :amount)
            ON CONFLICT(snapshot_id) DO UPDATE SET
                order_data = excluded.order_data,
                amount_cents = excluded.amount_cents
        ");
        $stmt->execute([
            ':id'     => $snapshotId,
            ':data'   => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ':amount' => $amountCents,
        ]);

        self::purge();

        return $snapshotId;
    }

    /**
     * @return array{order: array, amount_cents: int}|null Null si l'instantané est inconnu.
     */
    public static function load(string $snapshotId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $snapshotId)) {
            return null;
        }

        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("SELECT order_data, amount_cents FROM pending_payment WHERE snapshot_id = :id");
        $stmt->execute([':id' => $snapshotId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$row) {
            return null;
        }

        $order = json_decode((string) $row['order_data'], true);

        return is_array($order) ? ['order' => $order, 'amount_cents' => (int) $row['amount_cents']] : null;
    }

    /** Supprime les instantanés trop anciens. */
    public static function purge(): void
    {
        $db = InventoryManager::getDatabase();
        $db->exec("DELETE FROM pending_payment WHERE created_at < datetime('now', '-" . self::RETENTION_DAYS . " days')");
    }
}
