<?php

namespace Grav\Plugin\SimpleShop\Orders;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use RocketTheme\Toolbox\Event\Event;

/**
 * Gestionnaire des commandes (table `order` / `order_item`, voir `shop.sql`).
 *
 * Fournit :
 *  - la lecture de la liste complète des commandes pour l'espace admin
 *    (avec leurs articles et données décodées) via {@see self::getAllOrders()} ;
 *  - le changement de statut d'une commande via {@see self::updateStatus()},
 *    qui déclenche systématiquement l'événement `onSimpleShopOrderStatusChanged`
 *    (voir {@see self::fireStatusChangedEvent()}), CONSOMMÉ ÉGALEMENT par
 *    {@see \Grav\Plugin\SimpleShop\Inventory\CartStorage::toOrder()} au moment
 *    de la création d'une commande (statut initial 'paid').
 *
 * ⚠️ Les statuts définis dans {@see self::STATUSES} sont un choix par défaut
 * (paid, processing, shipped, delivered, cancelled, refunded) : ils n'étaient
 * imposés par aucune donnée métier existante et méritent d'être confirmés ou
 * ajustés.
 *
 * @package Grav\Plugin\SimpleShop\Orders
 */
class OrderManager
{
    /**
     * Table des statuts de commande disponibles : valeur technique (stockée
     * en base, colonne `order.status`) => libellé lisible pour l'admin.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'paid'       => 'Payée',
        'processing' => 'En préparation',
        'shipped'    => 'Expédiée',
        'delivered'  => 'Livrée',
        'cancelled'  => 'Annulée',
        'refunded'   => 'Remboursée',
    ];

    /**
     * Retourne le libellé lisible d'un statut, ou le statut brut si inconnu
     * (ex. valeur historique non prévue dans {@see self::STATUSES}).
     *
     * @param string $status Statut technique.
     *
     * @return string Le libellé à afficher.
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /**
     * Retourne l'ensemble des commandes, triées chronologiquement (les plus
     * récentes en premier), chacune enrichie de :
     *  - `customer`, `shipping`, `billing` : données JSON décodées ;
     *  - `items` : liste des lignes `order_item` associées.
     *
     * Prévu pour l'affichage du tableau de bord admin des commandes.
     *
     * ⚠️ Aucune pagination : à revoir si le volume de commandes devient
     * important.
     *
     * @return array<int, array> La liste des commandes enrichies.
     */
    public static function getAllOrders(): array
    {
        $db = InventoryManager::getDatabase();

        $stmt = $db->query('SELECT * FROM "order" ORDER BY created_at DESC, order_reference DESC');
        $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($orders)) {
            return [];
        }

        $stmtItems = $db->prepare('SELECT * FROM order_item WHERE order_reference = :ref ORDER BY id ASC');

        foreach ($orders as &$order) {
            $order['customer'] = json_decode((string) ($order['customer_data'] ?? '{}'), true) ?: [];
            $order['shipping'] = json_decode((string) ($order['shipping_data'] ?? '{}'), true) ?: [];
            $order['billing']  = json_decode((string) ($order['billing_data'] ?? '{}'), true) ?: [];

            $stmtItems->execute([':ref' => $order['order_reference']]);
            $order['items'] = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);
        }
        unset($order);

        return $orders;
    }

    /**
     * Récupère une commande brute (ligne `order`, sans enrichissement) par
     * référence.
     *
     * @param string $orderReference Référence de la commande.
     *
     * @return array|null La ligne de la commande, ou null si introuvable.
     */
    public static function getOrder(string $orderReference): ?array
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare('SELECT * FROM "order" WHERE order_reference = :ref');
        $stmt->execute([':ref' => $orderReference]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $order ?: null;
    }

    /**
     * Change le statut d'une commande et déclenche systématiquement
     * l'événement `onSimpleShopOrderStatusChanged` (voir
     * {@see self::fireStatusChangedEvent()}), SAUF si le nouveau statut est
     * strictement identique à l'ancien (aucun changement réel).
     *
     * @param string $orderReference Référence de la commande à modifier.
     * @param string $newStatus      Nouveau statut (doit exister dans {@see self::STATUSES}).
     *
     * @return bool Vrai si la mise à jour a été effectuée ou n'était pas
     *              nécessaire (statut déjà identique) ; faux si la commande
     *              est introuvable ou le statut invalide.
     */
    public static function updateStatus(string $orderReference, string $newStatus): bool
    {
        if (!array_key_exists($newStatus, self::STATUSES)) {
            return false;
        }

        $order = self::getOrder($orderReference);
        if (!$order) {
            return false;
        }

        $oldStatus = $order['status'] ?? null;

        if ($oldStatus === $newStatus) {
            // Rien à faire : on ne déclenche pas l'événement pour un
            // changement qui n'en est pas un.
            return true;
        }

        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare('UPDATE "order" SET status = :status WHERE order_reference = :ref');
        $stmt->execute([':status' => $newStatus, ':ref' => $orderReference]);

        self::fireStatusChangedEvent(Grav::instance(), $orderReference, $oldStatus, $newStatus);

        return true;
    }

    /**
     * Déclenche l'événement `onSimpleShopOrderStatusChanged`, consommable par
     * n'importe quel plugin/écouteur tiers (ex. notifications, intégrations
     * externes, mise à jour d'un CRM...).
     *
     * Payload de l'événement (accessible via `$event['...']`) :
     *  - `order_reference` (string)      : référence de la commande ;
     *  - `old_status`      (string|null) : statut précédent (null lors de la
     *                                       création de la commande) ;
     *  - `new_status`      (string)      : nouveau statut.
     *
     * Appelé par :
     *  - {@see \Grav\Plugin\SimpleShop\Inventory\CartStorage::toOrder()}, à la
     *    création de la commande (`old_status = null`, `new_status = 'paid'`) ;
     *  - {@see self::updateStatus()}, à chaque changement de statut ultérieur
     *    (ex. depuis l'espace admin).
     *
     * @param Grav        $grav           Conteneur Grav (pour `fireEvent()`).
     * @param string      $orderReference Référence de la commande concernée.
     * @param string|null $oldStatus      Statut précédent (null si création).
     * @param string      $newStatus      Nouveau statut.
     *
     * @return void
     */
    public static function fireStatusChangedEvent(Grav $grav, string $orderReference, ?string $oldStatus, string $newStatus): void
    {
        $grav->fireEvent('onSimpleShopOrderStatusChanged', new Event([
            'order_reference' => $orderReference,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
        ]));
    }
}
