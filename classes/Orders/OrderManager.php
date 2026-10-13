<?php

namespace Grav\Plugin\SimpleShop\Orders;

use Grav\Plugin\SimpleShop\Services\AddressFields;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Invoicing\InvoiceManager;
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
        return self::search()['items'];
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
     * Change le statut d'une commande, l'inscrit dans l'historique
     * (`order_status_history`) et déclenche systématiquement l'événement
     * `onSimpleShopOrderStatusChanged` (voir {@see self::fireStatusChangedEvent()}),
     * SAUF si le nouveau statut est strictement identique à l'ancien (aucun
     * changement réel).
     *
     * @param string      $orderReference Référence de la commande à modifier.
     * @param string      $newStatus      Nouveau statut (doit exister dans {@see self::STATUSES}).
     * @param string|null $changedBy      Auteur du changement (nom du compte admin), pour l'historique.
     *
     * @return bool Vrai si la mise à jour a été effectuée ou n'était pas
     *              nécessaire (statut déjà identique) ; faux si la commande
     *              est introuvable ou le statut invalide.
     */
    public static function updateStatus(string $orderReference, string $newStatus, ?string $changedBy = null): bool
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
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE "order" SET status = :status WHERE order_reference = :ref');
            $stmt->execute([':status' => $newStatus, ':ref' => $orderReference]);

            $stmtHist = $db->prepare(
                'INSERT INTO order_status_history (order_reference, old_status, new_status, changed_by)
                 VALUES (:ref, :old, :new, :by)'
            );
            $stmtHist->execute([':ref' => $orderReference, ':old' => $oldStatus, ':new' => $newStatus, ':by' => $changedBy]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        self::fireStatusChangedEvent(Grav::instance(), $orderReference, $oldStatus, $newStatus, $changedBy);

        return true;
    }

    /**
     * Historique des changements de statut d'une commande, du plus ancien au
     * plus récent.
     *
     * @return array<int, array{old_status:?string,new_status:string,changed_by:?string,changed_at:string}>
     */
    public static function getStatusHistory(string $orderReference): array
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare(
            'SELECT old_status, new_status, changed_by, changed_at
               FROM order_status_history
              WHERE order_reference = :ref
              ORDER BY id ASC'
        );
        $stmt->execute([':ref' => $orderReference]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Recherche paginée de commandes (espace admin).
     *
     * Filtres reconnus (tous facultatifs) :
     *  - `reference` : référence exacte d'une commande ;
     *  - `status`    : un statut de {@see self::STATUSES} ;
     *  - `q`         : texte libre cherché dans la référence, le nom, le
     *                  prénom, l'e-mail et le téléphone du client (SQLite JSON1) ;
     *  - `date_from` / `date_to` : bornes (AAAA-MM-JJ, incluses) sur la date
     *                  de création.
     *
     * @param array<string,mixed> $filters
     * @param int                 $limit  Taille de page (0 = tout, pour l'export).
     * @param int                 $offset Décalage.
     *
     * @return array{items: array<int,array>, total: int} Commandes enrichies
     *         (`customer`, `shipping`, `billing`, `items`) et total sans pagination.
     */
    public static function search(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $db = InventoryManager::getDatabase();

        [$where, $params] = self::buildWhere($db, $filters);

        $stmtCount = $db->prepare('SELECT COUNT(*) FROM "order"' . $where);
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $sql = 'SELECT * FROM "order"' . $where . ' ORDER BY created_at DESC, order_reference DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) $offset);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return ['items' => self::hydrate($stmt->fetchAll(\PDO::FETCH_ASSOC)), 'total' => $total];
    }

    /**
     * Replie un texte pour la recherche : minuscules et sans accents
     * (« Éloïse » -> « eloise »). Publique car appelée par SQLite
     * (`shop_fold()`), pas destinée à un autre usage.
     */
    public static function fold(?string $text): string
    {
        $text = mb_strtolower((string) $text, 'UTF-8');

        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($t !== null) {
                return (string) $t->transliterate($text);
            }
        }

        return strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);
    }

    /**
     * Construit la clause WHERE (et ses paramètres) d'une recherche.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function buildWhere(\PDO $db, array $filters): array
    {
        $clauses = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && array_key_exists($status, self::STATUSES)) {
            $clauses[] = 'status = :status';
            $params[':status'] = $status;
        }

        $reference = trim((string) ($filters['reference'] ?? ''));
        if ($reference !== '') {
            $clauses[] = 'order_reference = :reference';
            $params[':reference'] = $reference;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Recherche insensible à la casse ET aux accents : le LIKE de
            // SQLite ne l'est que pour l'ASCII, on compare donc des valeurs
            // « repliées » (voir self::fold()) des deux côtés.
            $db->sqliteCreateFunction('shop_fold', [self::class, 'fold'], 1);

            // Échappement des jokers LIKE saisis par l'utilisateur.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::fold($q)) . '%';
            $clauses[] = "(shop_fold(order_reference) LIKE :q ESCAPE '\\'"
                . " OR shop_fold(json_extract(customer_data, '$.lastname')) LIKE :q ESCAPE '\\'"
                . " OR shop_fold(json_extract(customer_data, '$.firstname')) LIKE :q ESCAPE '\\'"
                . " OR shop_fold(json_extract(customer_data, '$.email')) LIKE :q ESCAPE '\\'"
                . " OR shop_fold(json_extract(customer_data, '$.tel')) LIKE :q ESCAPE '\\')";
            $params[':q'] = $like;
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $date = (string) ($filters[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $clauses[] = "date(created_at) {$op} :{$key}";
                $params[':' . $key] = $date;
            }
        }

        return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /**
     * Enrichit des lignes `order` : données décodées et articles.
     *
     * @param array<int,array> $orders
     *
     * @return array<int,array>
     */
    private static function hydrate(array $orders): array
    {
        if (empty($orders)) {
            return [];
        }

        $db = InventoryManager::getDatabase();
        $stmtItems = $db->prepare('SELECT * FROM order_item WHERE order_reference = :ref ORDER BY id ASC');

        foreach ($orders as &$order) {
            $order['customer'] = AddressFields::normalize(json_decode((string) ($order['customer_data'] ?? '{}'), true) ?: []);
            $order['shipping'] = json_decode((string) ($order['shipping_data'] ?? '{}'), true) ?: [];
            $order['billing']  = AddressFields::normalize(json_decode((string) ($order['billing_data'] ?? '{}'), true) ?: []);

            $stmtItems->execute([':ref' => $order['order_reference']]);
            $order['items'] = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);
        }
        unset($order);

        return $orders;
    }

    /**
     * Export comptable CSV des commandes (mêmes filtres que {@see self::search()}).
     *
     * Format pensé pour Excel / LibreOffice en français : séparateur « ; »,
     * UTF-8 avec BOM, fins de ligne CRLF, montants à virgule décimale. Les
     * cellules commençant par = + - @ sont neutralisées (injection de
     * formules).
     *
     * @param array<string,mixed> $filters
     *
     * @return string Le contenu du fichier CSV.
     */
    public static function exportCsv(array $filters = []): string
    {
        $orders = self::search($filters)['items'];

        $header = [
            'Référence', 'Date', 'Statut', 'Nom', 'Prénom', 'E-mail', 'Téléphone',
            'Adresse', 'Complément', 'Code postal', 'Ville',
            'Livraison', 'Frais de livraison (€ TTC)', 'Total TTC (€)', 'Articles',
            'Facture', 'E-reporting',
        ];

        $db = InventoryManager::getDatabase();
        $stmtInv = $db->prepare('SELECT invoice_number, ereporting_status FROM invoice WHERE order_reference = :ref');

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        self::csvLine($handle, $header);

        foreach ($orders as $order) {
            $c = $order['customer'];
            $ship = $order['shipping'];

            $items = [];
            foreach ($order['items'] as $item) {
                $items[] = ((int) $item['quantity']) . ' × ' . $item['title'];
            }

            $stmtInv->execute([':ref' => $order['order_reference']]);
            $inv = $stmtInv->fetch(\PDO::FETCH_ASSOC) ?: [];

            self::csvLine($handle, [
                $order['order_reference'],
                $order['created_at'],
                self::statusLabel((string) $order['status']),
                $c['lastname'] ?? '', $c['firstname'] ?? '', $c['email'] ?? '', $c['tel'] ?? '',
                $c['street'] ?? '', $c['address-complement'] ?? '', $c['postal-code'] ?? '', $c['city'] ?? '',
                $ship['label'] ?? ($ship['name'] ?? ($ship['key'] ?? '')),
                number_format((float) ($ship['price'] ?? 0), 2, ',', ''),
                number_format((float) $order['total_amount'], 2, ',', ''),
                implode(' | ', $items),
                $inv['invoice_number'] ?? '',
                isset($inv['ereporting_status']) ? InvoiceManager::statusLabel((string) $inv['ereporting_status']) : '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Écrit une ligne CSV (séparateur « ; », CRLF) en neutralisant les formules.
     *
     * @param resource $handle
     * @param array<int,mixed> $cells
     */
    private static function csvLine($handle, array $cells): void
    {
        $clean = array_map(static function ($v) {
            $v = (string) $v;

            return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
        }, $cells);

        $line = '';
        foreach ($clean as $i => $cell) {
            $line .= ($i > 0 ? ';' : '')
                . ((strpbrk($cell, ";\"\r\n") !== false) ? '"' . str_replace('"', '""', $cell) . '"' : $cell);
        }
        fwrite($handle, $line . "\r\n");
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
     *  - `new_status`      (string)      : nouveau statut ;
     *  - `changed_by`      (string|null) : auteur du changement (compte admin),
     *                                       null si automatique.
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
     * @param string|null $changedBy      Auteur du changement (null si automatique).
     *
     * @return void
     */
    public static function fireStatusChangedEvent(Grav $grav, string $orderReference, ?string $oldStatus, string $newStatus, ?string $changedBy = null): void
    {
        $grav->fireEvent('onSimpleShopOrderStatusChanged', new Event([
            'order_reference' => $orderReference,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'changed_by'      => $changedBy,
        ]));
    }
}
