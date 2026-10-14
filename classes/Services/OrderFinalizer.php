<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Orders\PendingPayment;

/**
 * Création de commande à partir d'un paiement confirmé, avec ou sans session.
 *
 * Deux chemins mènent à la même commande : le retour du client sur le site
 * (avec sa session) et le webhook de la passerelle (sans session). Les deux
 * s'appuient sur la référence de paiement : la création est idempotente, le
 * premier arrivé crée la commande, le second retrouve la même (voir
 * {@see CartStorage::toOrder()}), et les e-mails ne partent qu'une fois.
 */
class OrderFinalizer
{
    /**
     * Finalise une commande depuis un webhook (aucune session).
     *
     * @param string $paymentReference Référence du paiement (ex. PaymentIntent).
     * @param string $snapshotId       Identifiant de l'instantané (métadonnées de la passerelle).
     * @param int    $amountPaidCents  Montant réellement encaissé, en centimes.
     *
     * @return array{ok: bool, created: bool, order: ?string, error: ?string, retry: bool}
     *         `retry` vrai = erreur transitoire, la passerelle doit renvoyer l'événement.
     */
    public static function finalizeFromSnapshot(string $paymentReference, string $snapshotId, int $amountPaidCents): array
    {
        $grav = Grav::instance();

        // Déjà traité (retour client arrivé avant le webhook, ou rejeu) ?
        $existing = CartStorage::findOrderByPaymentReference($paymentReference);
        if ($existing !== null) {
            return ['ok' => true, 'created' => false, 'order' => $existing, 'error' => null, 'retry' => false];
        }

        $snapshot = PendingPayment::load($snapshotId);
        if ($snapshot === null) {
            return self::failure("instantané {$snapshotId} introuvable", $paymentReference);
        }

        if ($amountPaidCents !== $snapshot['amount_cents']) {
            return self::failure("montant encaissé ({$amountPaidCents}) différent du montant attendu ({$snapshot['amount_cents']})", $paymentReference);
        }

        // Le panier a pu changer depuis le démarrage du paiement (autre
        // onglet) : le total recalculé doit toujours correspondre à l'encaissé.
        $order = $snapshot['order'];
        $cartId = (string) ($order['cart_id'] ?? '');
        $items = $cartId !== '' ? CartStorage::getCartItems($cartId) : [];
        $total = 0.0;
        foreach ($items as $item) {
            $total += ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 0));
        }
        $total += (float) (($order['shipping']['price'] ?? 0));
        if ($items === [] || max((int) round($total * 100), 50) !== $amountPaidCents) {
            return self::failure('panier absent ou modifié depuis le démarrage du paiement', $paymentReference);
        }

        try {
            $created = true;
            $reference = CartStorage::toOrder($paymentReference, $created, $order);
        } catch (\Throwable $e) {
            $grav['log']->error("[SimpleShop] Webhook : commande non créée pour le paiement {$paymentReference} : " . $e->getMessage());

            return ['ok' => false, 'created' => false, 'order' => null, 'error' => $e->getMessage(), 'retry' => true];
        }

        if ($created) {
            self::sendOrderEmails($grav, $reference);
        }

        return ['ok' => true, 'created' => $created, 'order' => $reference, 'error' => null, 'retry' => false];
    }

    /**
     * E-mails de commande (confirmation client + notification vendeur).
     * Volontairement non bloquant : une erreur est journalisée, jamais propagée.
     */
    public static function sendOrderEmails(Grav $grav, string $orderReference): void
    {
        try {
            $db = InventoryManager::getDatabase();

            $stmt = $db->prepare("SELECT * FROM \"order\" WHERE order_reference = :ref");
            $stmt->execute([':ref' => $orderReference]);
            $orderData = $stmt->fetch(\PDO::FETCH_ASSOC);
            // Libère le verrou de lecture SQLite : sinon la réservation du numéro de facture (autre connexion, même
            // requête) attend le délai maximal puis échoue (« database is locked »).
            $stmt->closeCursor();
            if (!$orderData) {
                return;
            }

            $stmtItems = $db->prepare("SELECT * FROM order_item WHERE order_reference = :ref");
            $stmtItems->execute([':ref' => $orderReference]);
            $orderItems = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

            $mailer = new OrderMailer($grav);
            $mailer->sendOrderConfirmation($orderData, $orderItems);
            $mailer->sendSellerNotification($orderData, $orderItems);
        } catch (\Throwable $e) {
            try {
                $grav['log']->error("[SimpleShop] Erreur lors de l'envoi des e-mails de commande ({$orderReference}) : " . $e->getMessage());
            } catch (\Throwable $inner) {
                // Ignoré : ne doit jamais faire échouer la finalisation.
            }
        }
    }

    /** Échec non transitoire : journalisé pour reprise manuelle, inutile de rejouer. */
    private static function failure(string $reason, string $paymentReference): array
    {
        Grav::instance()['log']->error("[SimpleShop] Webhook : paiement {$paymentReference} encaissé mais commande non créée ({$reason}). À rapprocher à la main.");

        return ['ok' => false, 'created' => false, 'order' => null, 'error' => $reason, 'retry' => false];
    }
}
