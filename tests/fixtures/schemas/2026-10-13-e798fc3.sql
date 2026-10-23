-- Schéma de la base créée par simple-shop e798fc3 (2026-10-13) : shop.sql + colonnes ajoutées (ADDED_COLUMNS) de cette version.
-- Généré depuis l'historique Git ; ne pas modifier (sert à tester la migration vers la version courante).
-- 1. Gestion des paniers anonymes (Porte l'expiration globale)
CREATE TABLE IF NOT EXISTS cart (
    cart_id TEXT PRIMARY KEY,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL
);

-- 2. Articles du panier (Détermine le stock virtuel réservé)
CREATE TABLE IF NOT EXISTS cart_item (
    cart_id TEXT,
    product_route TEXT,
    quantity INTEGER NOT NULL,
    PRIMARY KEY (cart_id, product_route),
    FOREIGN KEY(cart_id) REFERENCES cart(cart_id) ON DELETE CASCADE
);

-- 3. Commandes validées
CREATE TABLE IF NOT EXISTS "order" (
    order_reference TEXT PRIMARY KEY,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    customer_data TEXT NOT NULL,
    shipping_data TEXT NOT NULL,
    billing_data TEXT NOT NULL,
    total_amount REAL NOT NULL,
    status TEXT DEFAULT 'paid'
);

-- 4. Articles de la commande
CREATE TABLE IF NOT EXISTS order_item (
    id INTEGER PRIMARY KEY,
    order_reference TEXT,
    product_route TEXT NOT NULL,
    title TEXT NOT NULL,
    price REAL NOT NULL,
    quantity INTEGER NOT NULL,
    FOREIGN KEY(order_reference) REFERENCES "order"(order_reference) ON DELETE CASCADE
);

-- 5. Factures Factur-X / suivi e-reporting (voir CDC-E-REPORTING.md et
-- classes/Invoicing/InvoiceManager.php). Les noms de colonnes ainsi que les
-- valeurs de `ereporting_status` sont volontairement en anglais, par
-- cohérence avec order.status (voir OrderManager::STATUSES) ; la
-- correspondance avec les termes légaux français du CDC est documentée dans
-- InvoiceManager::STATUSES.
--
-- NOTE (migration additive) : cette table a été ajoutée après la mise en
-- production initiale du plugin. Sur les installations existantes, elle est
-- créée automatiquement grâce à la ré-exécution systématique de ce fichier à
-- chaque ouverture de connexion (voir InventoryManager::getDatabase()),
-- rendue sûre par l'usage de IF NOT EXISTS partout.
CREATE TABLE IF NOT EXISTS invoice (
    id INTEGER PRIMARY KEY,
    order_reference TEXT NOT NULL UNIQUE,
    invoice_number TEXT NOT NULL UNIQUE,
    customer_type TEXT NOT NULL DEFAULT 'b2c',
    ereporting_status TEXT NOT NULL DEFAULT 'not_submitted',
    vat_rate REAL NOT NULL DEFAULT 0,
    pdf_path TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_reference) REFERENCES "order"(order_reference) ON DELETE CASCADE
);

-- 6. Rattachement d'un paiement à sa commande (idempotence). La référence de
-- paiement (ex. identifiant PaymentIntent Stripe) est UNIQUE : un même
-- paiement ne peut jamais créer deux commandes, même si le client recharge la
-- page de retour, revient en arrière ou clique deux fois (voir
-- CartStorage::toOrder()).
CREATE TABLE IF NOT EXISTS order_payment (
    payment_reference TEXT PRIMARY KEY,
    order_reference TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_reference) REFERENCES "order"(order_reference) ON DELETE CASCADE
);

-- 6bis. Instantanés de commande en attente de paiement. À chaque démarrage de
-- paiement, la passerelle y enregistre ce qu'il faut pour créer la commande SANS
-- session (cart_id, client, livraison, facturation, montant attendu) ; seul
-- l'identifiant `snapshot_id` est confié à la passerelle (métadonnées Stripe).
-- Le webhook s'en sert quand le client a quitté le site avant le retour.
-- Purgés après 30 jours (voir PendingPayment::purge()).
CREATE TABLE IF NOT EXISTS pending_payment (
    snapshot_id TEXT PRIMARY KEY,
    order_data TEXT NOT NULL,
    amount_cents INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 7. Historique des changements de statut d'une commande (un changement =
-- une ligne, y compris la création : old_status NULL -> 'paid'). `changed_by`
-- identifie l'auteur d'un changement manuel (compte admin) ; NULL pour un
-- changement automatique (création de la commande).
CREATE TABLE IF NOT EXISTS order_status_history (
    id INTEGER PRIMARY KEY,
    order_reference TEXT NOT NULL,
    old_status TEXT,
    new_status TEXT NOT NULL,
    changed_by TEXT,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_reference) REFERENCES "order"(order_reference) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_status_history_order ON order_status_history(order_reference);

-- 8. Numéro de suivi du colis (saisi dans l'administration, repris dans l'e-mail « expédiée »)
CREATE TABLE IF NOT EXISTS order_tracking (
    order_reference TEXT PRIMARY KEY,
    tracking_number TEXT NOT NULL,
    updated_by TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(order_reference) REFERENCES "order"(order_reference) ON DELETE CASCADE
);
