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

    /**
     * Identifiant d'une ligne de panier : empreinte de la route du produit et de ses options normalisées (clés triées).
     * Deux ajouts du même produit avec les mêmes options tombent sur la même ligne ; d'autres options = une autre ligne.
     */
    public static function lineId(string $route, array $options): string
    {
        return substr(sha1($route . "\n" . self::optionsJson($options)), 0, 16);
    }

    /** Options sous forme canonique (clés triées récursivement), pour l'empreinte et le stockage. */
    public static function optionsJson(array $options): string
    {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            if (array_keys($v) !== range(0, count($v) - 1)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };
        $canonical = $sort($options);

        return $canonical === [] ? '{}' : (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lignes du panier, indexées par line_id : route, options, libellé, prix (centimes et décimal TTC), taxe, quantité, image.
     */
    public static function getCartItems(string $cartId): array
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("SELECT line_id, product_route, options, quantity FROM cart_line WHERE cart_id = :id ORDER BY rowid");
        $stmt->execute([':id' => $cartId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pages = Grav::instance()['pages'];
        $pages->init();
        $structuredCart = [];

        foreach ($items as $item) {
            $route = (string) $item['product_route'];
            $page = $pages->dispatch($route);
            if (!$page) continue;
            $options = json_decode((string) $item['options'], true);
            $options = is_array($options) ? $options : [];

            // Libellé et image : présentation du type de produit (blueprint `shop.presentation`).
            $type = \Grav\Plugin\SimpleShop\Product\ProductTypes::forPage($page);
            $presentation = $type ? $type->presentation($page, $options) : ['label' => $page->title(), 'image' => null];
            // Prix calculé par le type (centimes, taxe, devise) ; `price` (décimal TTC) reste pour les gabarits.
            $data = InventoryManager::getProductData($route, $options);
            $price = $data !== false ? $data['price'] : null;
            $structuredCart[(string) $item['line_id']] = [
                'line_id' => (string) $item['line_id'],
                'route' => $route,
                'options' => $options,
                'title' => $presentation['label'],
                'price' => $price ? $price->unitTtc->toFloat() : 0.0,
                'unit_ttc_cents' => $price ? $price->unitTtc->amount : 0,
                'unit_ht_cents' => $price ? $price->unitHt->amount : 0,
                'tax_code' => $price ? $price->tax->code : null,
                'tax_bp' => $price ? $price->tax->basisPoints : null,
                'tax_components' => $price ? array_map(static fn ($c) => $c->jsonSerialize(), $price->tax->components) : [],
                // Article expédié (le type renvoie des mesures) : seuls ceux-là décident de la taxe de la livraison.
                'shippable' => $type ? $type->shipping($page, $options) !== null : true,
                'currency' => $price ? $price->currency() : null,
                'product_type' => $type ? $type->name : null,
                'quantity' => (int)$item['quantity'],
                'cover_path' => $presentation['image'],
            ];
        }

        return $structuredCart;
    }

    /** Quantité d'une ligne (0 si absente). */
    public static function lineQuantity(string $cartId, string $lineId): int
    {
        $stmt = InventoryManager::getDatabase()->prepare("SELECT quantity FROM cart_line WHERE cart_id = :cid AND line_id = :lid");
        $stmt->execute([':cid' => $cartId, ':lid' => $lineId]);
        $q = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $q;
    }

    /** Route du produit d'une ligne (null si la ligne n'existe pas). */
    public static function lineRoute(string $cartId, string $lineId): ?string
    {
        $stmt = InventoryManager::getDatabase()->prepare("SELECT product_route FROM cart_line WHERE cart_id = :cid AND line_id = :lid");
        $stmt->execute([':cid' => $cartId, ':lid' => $lineId]);
        $r = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $r === false ? null : (string) $r;
    }

    /** Ajoute $quantity exemplaires (ligne créée si besoin). */
    public static function addLine(string $cartId, string $route, array $options, int $quantity = 1): string
    {
        $lineId = self::lineId($route, $options);
        $stmt = InventoryManager::getDatabase()->prepare("
            INSERT INTO cart_line (cart_id, line_id, product_route, options, quantity)
            VALUES (:cid, :lid, :route, :options, :qty)
            ON CONFLICT(cart_id, line_id) DO UPDATE SET quantity = quantity + excluded.quantity
        ");
        $stmt->execute([':cid' => $cartId, ':lid' => $lineId, ':route' => $route, ':options' => self::optionsJson($options), ':qty' => $quantity]);

        return $lineId;
    }

    /** Fixe la quantité d'une ligne (0 ou moins : la ligne est retirée). */
    public static function setLineQuantity(string $cartId, string $lineId, int $quantity): void
    {
        if ($quantity <= 0) {
            self::deleteLine($cartId, $lineId);

            return;
        }
        $stmt = InventoryManager::getDatabase()->prepare("UPDATE cart_line SET quantity = :qty WHERE cart_id = :cid AND line_id = :lid");
        $stmt->execute([':qty' => $quantity, ':cid' => $cartId, ':lid' => $lineId]);
    }

    public static function deleteLine(string $cartId, string $lineId): void
    {
        $stmt = InventoryManager::getDatabase()->prepare("DELETE FROM cart_line WHERE cart_id = :cid AND line_id = :lid");
        $stmt->execute([':cid' => $cartId, ':lid' => $lineId]);
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
        $stmt = $db->prepare("SELECT line_id, product_route, options, quantity FROM cart_line WHERE cart_id = :cid");
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
            $lineId = (string) $item['line_id'];
            $options = json_decode((string) $item['options'], true);
            $currentQty = (int) $item['quantity'];

            $productData = InventoryManager::getProductData($route, is_array($options) ? $options : []);

            // Résolution du titre pour affichage (repli sur la route brute si
            // la page n'est plus dispatchable, ex. produit dépublié).
            $page = $pages->dispatch($route);
            $title = $page ? $page->title() : $route;

            if ($productData === false) {
                // Produit devenu totalement non vendable : la donnée de stock
                // n'a plus aucun sens, la ligne est retirée dans son intégralité.
                self::deleteLine($cartId, $lineId);

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
                self::setLineQuantity($cartId, $lineId, max(0, $maxAllowable));

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

        $stmtCount = $db->prepare("SELECT COUNT(*) FROM cart_line WHERE cart_id = :cid");
        $stmtCount->execute([':cid' => $targetCartId]);
        $count = (int) $stmtCount->fetchColumn();
        $stmtCount->closeCursor();
        if ($count > 0) {
            // Le panier courant n'est pas vide : conformément à la règle
            // métier demandée, on ne restaure rien.
            return false;
        }

        $stmtSource = $db->prepare("SELECT line_id, product_route, options, quantity FROM cart_line WHERE cart_id = :cid");
        $stmtSource->execute([':cid' => $sourceCartId]);
        $sourceItems = $stmtSource->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($sourceItems)) {
            return false;
        }

        self::touchCart($targetCartId);

        $db->beginTransaction();
        try {
            $stmtInsert = $db->prepare("
                INSERT INTO cart_line (cart_id, line_id, product_route, options, quantity)
                VALUES (:cart_id, :lid, :route, :options, :qty)
                ON CONFLICT(cart_id, line_id) DO UPDATE SET quantity = excluded.quantity
            ");
            foreach ($sourceItems as $item) {
                $stmtInsert->execute([
                    ':cart_id' => $targetCartId,
                    ':lid'     => $item['line_id'],
                    ':route'   => $item['product_route'],
                    ':options' => $item['options'],
                    ':qty'     => $item['quantity'],
                ]);
            }

            // Nettoyage de l'ancien panier fusionné, pour éviter toute
            // restauration en double lors d'une prochaine connexion.
            $stmtDeleteItems = $db->prepare("DELETE FROM cart_line WHERE cart_id = :cid");
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
    
    /**
     * @param string|null $paymentReference Référence du paiement (idempotence).
     * @param bool|null   $created          Sortie : faux si la commande existait déjà.
     * @param array|null  $snapshot         Commande en cours reconstituée SANS session
     *                                      (webhook : cart_id, customer, shipping, billing,
     *                                      voir PendingPayment) ; null = utiliser la session.
     */
    public static function toOrder(?string $paymentReference = null, ?bool &$created = null, ?array $snapshot = null): string
    {
        $created = true;

        // IDEMPOTENCE : si ce paiement a déjà donné lieu à une commande
        // (rechargement de la page de retour, retour arrière, double clic),
        // on renvoie la commande existante SANS rien recréer (ni déstockage,
        // ni événement) ; l'appelant sait via `$created` qu'il ne doit pas
        // renvoyer d'e-mails.
        if ($paymentReference !== null && $paymentReference !== '') {
            $existing = self::findOrderByPaymentReference($paymentReference);
            if ($existing !== null) {
                $created = false;

                return $existing;
            }
        }

        $session = Grav::instance()['session'];
        if ($snapshot !== null) {
            $order = (object) $snapshot;
        } else {
            if (!isset($session->shop_order)) {
                throw new \RuntimeException('Session de commande expirée.');
            }
            $order = $session->shop_order;
        }
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

        // Montants en centimes (une seule source avec le paiement et la facture), taxe figée par ligne.
        $amounts = \Grav\Plugin\SimpleShop\Orders\OrderAmounts::forSite($cartItems, isset($order->shipping) ? (array) $order->shipping : []);
        $totalAmount = \Grav\Plugin\SimpleShop\Orders\OrderAmounts::toDecimal($amounts['total_ttc_cents']);
        $ship = $amounts['shipping'];

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
                INSERT INTO \"order\" (order_reference, customer_data, shipping_data, billing_data, total_amount, status,
                    currency, total_cents, shipping_ht_cents, shipping_ttc_cents, shipping_tax_code, shipping_tax_bp, shipping_taxes)
                VALUES (:ref, :customer, :shipping, :billing, :total, 'paid',
                    :currency, :total_cents, :ship_ht, :ship_ttc, :ship_code, :ship_bp, :ship_taxes)
            ");
            $siteTax = \Grav\Plugin\SimpleShop\Product\Catalog::taxRate();
            $stmtOrder->execute([
                ':ref'         => $orderReference,
                ':customer'    => $customerJson,
                ':shipping'    => $shippingJson,
                ':billing'     => $billingJson,
                ':total'       => $totalAmount,
                ':currency'    => $amounts['currency'],
                ':total_cents' => $amounts['total_ttc_cents'],
                ':ship_ht'     => $amounts['shipping_ht_cents'],
                ':ship_ttc'    => $amounts['shipping_ttc_cents'],
                ':ship_code'   => $ship['tax_code'] ?? $siteTax->code,
                ':ship_bp'     => $ship['tax_bp'] ?? $siteTax->basisPoints,
                // Une part par taxe (panier mixte au prorata) : titre, HT, TTC, taxe et ses composantes.
                ':ship_taxes'  => json_encode(array_map(static fn ($l) => ['title' => $l['title'], 'ht' => $l['unit_ht_cents'], 'ttc' => $l['unit_ttc_cents'],
                    'code' => $l['tax_code'], 'bp' => $l['tax_bp'], 'taxes' => $l['taxes']], $amounts['shipping_lines']), JSON_UNESCAPED_UNICODE),
            ]);

            // Rattachement du paiement à la commande : la clé primaire garantit
            // qu'un même paiement ne crée jamais deux commandes (deux requêtes
            // simultanées : la seconde échoue ici et est traitée plus bas).
            if ($paymentReference !== null && $paymentReference !== '') {
                $stmtPay = $db->prepare("
                    INSERT INTO order_payment (payment_reference, order_reference)
                    VALUES (:pay, :ref)
                ");
                $stmtPay->execute([':pay' => $paymentReference, ':ref' => $orderReference]);
            }

            // Première ligne de l'historique des statuts (création -> 'paid').
            $stmtHist = $db->prepare("
                INSERT INTO order_status_history (order_reference, old_status, new_status, changed_by)
                VALUES (:ref, NULL, 'paid', NULL)
            ");
            $stmtHist->execute([':ref' => $orderReference]);

            // Insertion des articles de la commande
            $stmtItem = $db->prepare("
                INSERT INTO order_item (order_reference, product_route, title, price, quantity,
                    unit_ht_cents, unit_ttc_cents, tax_code, tax_bp, product_type, line_id, options, taxes)
                VALUES (:ref, :route, :title, :price, :qty, :ht, :ttc, :code, :bp, :type, :lid, :options, :taxes)
            ");
            foreach ($amounts['lines'] as $line) {
                if ($line['kind'] !== 'item') {
                    continue;
                }
                $stmtItem->execute([
                    ':ref'   => $orderReference,
                    ':route' => $line['route'],
                    ':title' => $line['title'],
                    ':price' => \Grav\Plugin\SimpleShop\Orders\OrderAmounts::toDecimal($line['unit_ttc_cents']),
                    ':qty'   => $line['quantity'],
                    ':ht'    => $line['unit_ht_cents'],
                    ':ttc'   => $line['unit_ttc_cents'],
                    ':code'  => $line['tax_code'],
                    ':bp'    => $line['tax_bp'],
                    ':type'  => $cartItems[$line['line_id']]['product_type'] ?? null,
                    ':lid'   => $line['line_id'],
                    ':options' => self::optionsJson((array) ($cartItems[$line['line_id']]['options'] ?? [])),
                    ':taxes' => json_encode($line['taxes'], JSON_UNESCAPED_UNICODE),
                ]);
            }

            // Vidage du panier en base
            $stmtClear = $db->prepare("DELETE FROM cart WHERE cart_id = :cid");
            $stmtClear->execute([':cid' => $cartId]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();

            // Course perdue contre une requête simultanée portant sur le même
            // paiement : la commande existe déjà, on la renvoie.
            if ($paymentReference !== null && $paymentReference !== '') {
                $existing = self::findOrderByPaymentReference($paymentReference);
                if ($existing !== null) {
                    $created = false;

                    return $existing;
                }
            }

            throw new \RuntimeException("Erreur lors de la persistance de la commande : " . $e->getMessage());
        }

        // Déstockage automatique (décision : option « a ») : le panier vient
        // d'être supprimé, donc sa réservation disparaît ; le stock physique
        // (`product_quantity`) baisse au même instant. Un échec d'écriture est
        // journalisé mais ne remet pas en cause la commande déjà enregistrée.
        foreach ($cartItems as $item) {
            InventoryManager::decrementPhysicalStock((string) $item['route'], (int) ($item['quantity'] ?? 0));
        }

        // Déclenchement de l'événement `onSimpleShopOrderStatusChanged` dès la
        // création de la commande (statut initial 'paid', voir
        // OrderManager::fireStatusChangedEvent()). Tout écouteur tiers
        // enregistré sur cet événement sera notifié, indépendamment de
        // CartController::sendOrderEmails() (appel séparé, non lié).
        OrderManager::fireStatusChangedEvent(Grav::instance(), $orderReference, null, 'paid');

        // Nettoyage de la session commande en cours (jamais depuis un webhook :
        // la session courante n'est pas celle du client).
        if ($snapshot === null) {
            unset($session->shop_order);
        }

        return $orderReference;
    }

    /**
     * Retrouve la commande déjà créée pour une référence de paiement.
     *
     * @return string|null La référence de commande, ou null s'il n'y en a pas.
     */
    public static function findOrderByPaymentReference(string $paymentReference): ?string
    {
        $db = InventoryManager::getDatabase();
        $stmt = $db->prepare("SELECT order_reference FROM order_payment WHERE payment_reference = :pay");
        $stmt->execute([':pay' => $paymentReference]);
        $ref = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $ref !== false ? (string) $ref : null;
    }
}
