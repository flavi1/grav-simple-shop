<?php
// CartStorage.php

namespace Grav\Plugin\SimpleShop\Inventory;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Orders\OrderManager;

class CartStorage
{
    public static function getOrCreateCartId(): string
    {
        $session = Grav::instance()['session'];
        if (!isset($session->shop_order)) {
            $session->shop_order = new \stdClass();
        }

        if (empty($session->shop_order->cart_id)) {
            $session->shop_order->cart_id = time() . '_' . bin2hex(random_bytes(8));
        }

        return $session->shop_order->cart_id;
    }

    public static function touchCart(string $cartId): void
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("
            INSERT INTO cart (cart_id, expires_at) 
            VALUES (:id, datetime('now', '+2 hours'))
            ON CONFLICT(cart_id) DO UPDATE SET 
                updated_at = CURRENT_TIMESTAMP,
                expires_at = datetime('now', '+2 hours')
        ");
        $stmt->execute([':id' => $cartId]);
    }

    public static function getCartItems(string $cartId): array
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("SELECT product_route, quantity FROM cart_item WHERE cart_id = :id");
        $stmt->execute([':id' => $cartId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pages = Grav::instance()['pages'];
        $structuredCart = [];

        foreach ($items as $item) {
            $pages->init();
            $page = $pages->dispatch($item['product_route']);
            if (!$page) continue;

            $header = $page->header();
            $structuredCart[$item['product_route']] = [
                'title' => $page->title(),
                'price' => InventoryManager::getProductPrice($item['product_route']) ?? 0.0,
                'quantity' => (int)$item['quantity'],
                'cover_path' => !empty($header->book_cover) ? current($header->book_cover)['path'] : null
            ];
        }

        return $structuredCart;
    }
    
    public static function getCart() {
        $cartId = self::getOrCreateCartId();
        return self::getCartItems($cartId);
    }

    /**
     * Revalide l'intégralité du panier `$cartId` par rapport au stock virtuel
     * courant de chaque produit ({@see InventoryManager::getProductData()}).
     *
     * À appeler systématiquement en tout début de chaque étape du tunnel
     * d'achat ({@see \Grav\Plugin\SimpleShop\Controllers\CartController}),
     * afin de détecter et corriger silencieusement en base toute
     * incohérence apparue depuis le dernier ajout au panier : rupture de
     * stock, produit dépublié/retiré de la vente, prévente expirée sans prix
     * défini, etc. Cette revalidation n'a JAMAIS lieu à l'ajout d'un article
     * (déjà couvert par la logique de `CartController::step1Cart()`), mais
     * comble le cas d'un panier resté ouvert alors que le stock a changé
     * entre-temps (autre client, action admin...), y compris après une
     * restauration de panier via {@see self::restoreCartIfEmpty()}.
     *
     * Règles appliquées, par ligne de panier :
     *  - produit non vendable du tout (dépublié, hors modalité "Vendre ici",
     *    prévente sans prix...) : la ligne est intégralement SUPPRIMÉE ;
     *  - stock infini (`-1`) : aucune revalidation, la ligne est ignorée ;
     *  - stock fini : la quantité maximale autorisée pour CETTE ligne est
     *    `virtualStock + currentQty` (même formule que celle déjà utilisée
     *    par `CartController::step1Cart()` pour `add_item`/`update_quantity`,
     *    car le stock virtuel intègre déjà la réservation de cette ligne).
     *    Si la quantité en panier dépasse ce maximum, elle est réduite à ce
     *    maximum (ou la ligne est supprimée si ce maximum est nul).
     *
     * @param string $cartId Identifiant du panier à revalider.
     *
     * @return array<int, array{product_route: string, title: string, requested: int, available: int}>
     *               La liste des ajustements réellement effectués (vide si le
     *               panier était déjà entièrement cohérent). `available = 0`
     *               signifie que la ligne a été intégralement supprimée.
     */
    public static function revalidateStock(string $cartId): array
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("SELECT product_route, quantity FROM cart_item WHERE cart_id = :cid");
        $stmt->execute([':cid' => $cartId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($items)) {
            return [];
        }

        $pages = Grav::instance()['pages'];
        $pages->init();

        $adjustments = [];

        foreach ($items as $item) {
            $route = $item['product_route'];
            $currentQty = (int) $item['quantity'];

            $productData = InventoryManager::getProductData($route);

            // Résolution du titre pour affichage (repli sur la route brute si
            // la page n'est plus dispatchable, ex. produit dépublié).
            $page = $pages->dispatch($route);
            $title = $page ? $page->title() : $route;

            if ($productData === false) {
                // Produit devenu totalement non vendable : la donnée de stock
                // n'a plus aucun sens, la ligne est retirée dans son intégralité.
                self::deleteCartItem($cartId, $route);

                $adjustments[] = [
                    'product_route' => $route,
                    'title'         => $title,
                    'requested'     => $currentQty,
                    'available'     => 0,
                ];

                continue;
            }

            $virtualStock = $productData['product_virtual_stock'];

            if ($virtualStock === -1) {
                // Stock infini : rien à revalider pour cette ligne.
                continue;
            }

            // Le stock virtuel intègre déjà la réservation de CETTE ligne
            // (voir InventoryManager::getProductData()) : la quantité maximale
            // pour ce panier est donc virtualStock + currentQty (même formule
            // que CartController::step1Cart() pour add_item/update_quantity).
            $maxAllowable = $virtualStock + $currentQty;

            if ($currentQty > $maxAllowable) {
                if ($maxAllowable <= 0) {
                    self::deleteCartItem($cartId, $route);
                } else {
                    $stmtUpdate = $db->prepare("
                        UPDATE cart_item SET quantity = :qty
                        WHERE cart_id = :cid AND product_route = :route
                    ");
                    $stmtUpdate->execute([
                        ':qty'   => $maxAllowable,
                        ':cid'   => $cartId,
                        ':route' => $route,
                    ]);
                }

                $adjustments[] = [
                    'product_route' => $route,
                    'title'         => $title,
                    'requested'     => $currentQty,
                    'available'     => max(0, $maxAllowable),
                ];
            }
        }

        return $adjustments;
    }

    /**
     * Supprime une ligne de panier donnée.
     *
     * Factorisée pour {@see self::revalidateStock()} (suppression d'une
     * ligne devenue non vendable ou totalement épuisée).
     *
     * @param string $cartId Identifiant du panier.
     * @param string $route  Route du produit à retirer.
     *
     * @return void
     */
    private static function deleteCartItem(string $cartId, string $route): void
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("DELETE FROM cart_item WHERE cart_id = :cid AND product_route = :route");
        $stmt->execute([':cid' => $cartId, ':route' => $route]);
    }

    /**
     * Restaure le contenu d'un ancien panier (`$sourceCartId`) dans le panier
     * courant (`$targetCartId`), UNIQUEMENT si ce dernier est actuellement
     * vide. Utilisée par `CustomerManager::attemptAutoLogin()` pour restaurer
     * le panier mémorisé lors d'une précédente session ("keep-data" coché),
     * sans jamais écraser un panier en cours de constitution.
     *
     * Les lignes de l'ancien panier sont recopiées telles quelles (quantités
     * incluses) SANS revalidation par rapport au stock virtuel courant : cette
     * revalidation est désormais assurée de façon centralisée et systématique
     * par {@see self::revalidateStock()}, appelée en tout début de chaque
     * étape du tunnel d'achat ({@see \Grav\Plugin\SimpleShop\Controllers\CartController}),
     * donc immédiatement après toute restauration.
     *
     * L'ancien panier (lignes + entrée `cart`) est supprimé après une fusion
     * réussie, afin qu'une éventuelle nouvelle connexion automatique
     * ultérieure ne le restaure pas une seconde fois.
     *
     * @param string $targetCartId Identifiant du panier courant (session en cours).
     * @param string $sourceCartId Identifiant de l'ancien panier à restaurer.
     *
     * @return bool Vrai si une restauration a effectivement eu lieu.
     */
    public static function restoreCartIfEmpty(string $targetCartId, string $sourceCartId): bool
    {
        if ($sourceCartId === '' || $sourceCartId === $targetCartId) {
            return false;
        }

        $db = InventoryManager::getDatabase();

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM cart_item WHERE cart_id = :cid");
        $stmtCount->execute([':cid' => $targetCartId]);
        if ((int) $stmtCount->fetchColumn() > 0) {
            // Le panier courant n'est pas vide : conformément à la règle
            // métier demandée, on ne restaure rien.
            return false;
        }

        $stmtSource = $db->prepare("SELECT product_route, quantity FROM cart_item WHERE cart_id = :cid");
        $stmtSource->execute([':cid' => $sourceCartId]);
        $sourceItems = $stmtSource->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($sourceItems)) {
            return false;
        }

        self::touchCart($targetCartId);

        $db->beginTransaction();
        try {
            $stmtInsert = $db->prepare("
                INSERT INTO cart_item (cart_id, product_route, quantity)
                VALUES (:cart_id, :route, :qty)
                ON CONFLICT(cart_id, product_route) DO UPDATE SET quantity = excluded.quantity
            ");
            foreach ($sourceItems as $item) {
                $stmtInsert->execute([
                    ':cart_id' => $targetCartId,
                    ':route'   => $item['product_route'],
                    ':qty'     => $item['quantity'],
                ]);
            }

            // Nettoyage de l'ancien panier fusionné, pour éviter toute
            // restauration en double lors d'une prochaine connexion.
            $stmtDeleteItems = $db->prepare("DELETE FROM cart_item WHERE cart_id = :cid");
            $stmtDeleteItems->execute([':cid' => $sourceCartId]);

            $stmtDeleteCart = $db->prepare("DELETE FROM cart WHERE cart_id = :cid");
            $stmtDeleteCart->execute([':cid' => $sourceCartId]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }

        return true;
    }
    
    public static function toOrder(): string
    {
        $session = Grav::instance()['session'];
        if (!isset($session->shop_order)) {
            throw new \RuntimeException('Session de commande expirée.');
        }

        $order = $session->shop_order;
        $cartId = $order->cart_id ?? null;

        if (!$cartId) {
            throw new \RuntimeException('Panier introuvable.');
        }

        $cartItems = self::getCartItems($cartId);
        if (empty($cartItems)) {
            throw new \RuntimeException('Impossible de valider une commande avec un panier vide.');
        }

        $db = InventoryManager::getDatabase();
        $orderReference = 'CMD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $subtotal += ($item['price'] ?? 0.0) * ($item['quantity'] ?? 0);
        }
        $shippingPrice = isset($order->shipping['price']) ? (float)$order->shipping['price'] : 0.0;
        $totalAmount = $subtotal + $shippingPrice;

        $customerData = isset($order->customer) ? (array)$order->customer : [];
        $shippingData = isset($order->shipping) ? (array)$order->shipping : [];
        $billingData  = isset($order->billing) ? (array)$order->billing : [];

        $customerJson = json_encode($customerData, JSON_UNESCAPED_UNICODE);
        $shippingJson = json_encode($shippingData, JSON_UNESCAPED_UNICODE);
        $billingJson  = json_encode($billingData, JSON_UNESCAPED_UNICODE);

        $db->beginTransaction();
        try {
            // Insertion de la commande
            $stmtOrder = $db->prepare("
                INSERT INTO \"order\" (order_reference, customer_data, shipping_data, billing_data, total_amount, status)
                VALUES (:ref, :customer, :shipping, :billing, :total, 'paid')
            ");
            $stmtOrder->execute([
                ':ref'      => $orderReference,
                ':customer' => $customerJson,
                ':shipping' => $shippingJson,
                ':billing'  => $billingJson,
                ':total'    => $totalAmount
            ]);

            // Insertion des articles de la commande
            $stmtItem = $db->prepare("
                INSERT INTO order_item (order_reference, product_route, title, price, quantity)
                VALUES (:ref, :route, :title, :price, :qty)
            ");
            foreach ($cartItems as $route => $item) {
                $stmtItem->execute([
                    ':ref'   => $orderReference,
                    ':route' => (string)$route,
                    ':title' => (string)($item['title'] ?? ''),
                    ':price' => (float)($item['price'] ?? 0.0),
                    ':qty'   => (int)($item['quantity'] ?? 0)
                ]);
            }

            // Vidage du panier en base
            $stmtClear = $db->prepare("DELETE FROM cart WHERE cart_id = :cid");
            $stmtClear->execute([':cid' => $cartId]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw new \RuntimeException("Erreur lors de la persistance de la commande : " . $e->getMessage());
        }

        // Déstockage automatique (décision : option « a ») : le panier vient
        // d'être supprimé, donc sa réservation disparaît ; le stock physique
        // (`product_quantity`) baisse au même instant. Un échec d'écriture est
        // journalisé mais ne remet pas en cause la commande déjà enregistrée.
        foreach ($cartItems as $route => $item) {
            InventoryManager::decrementPhysicalStock((string) $route, (int) ($item['quantity'] ?? 0));
        }

        // Déclenchement de l'événement `onSimpleShopOrderStatusChanged` dès la
        // création de la commande (statut initial 'paid', voir
        // OrderManager::fireStatusChangedEvent()). Tout écouteur tiers
        // enregistré sur cet événement sera notifié, indépendamment de
        // CartController::sendOrderEmails() (appel séparé, non lié).
        OrderManager::fireStatusChangedEvent(Grav::instance(), $orderReference, null, 'paid');

        // Nettoyage de la session commande en cours
        unset($session->shop_order);

        return $orderReference;
    }
}
