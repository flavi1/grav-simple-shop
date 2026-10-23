<?php
// InventoryManager.php

namespace Grav\Plugin\SimpleShop\Inventory;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Product\Catalog;

class InventoryManager
{
    /**
     * Calcule le stock virtuel et récupère le prix à partir du fichier .md du produit 
     * et des réservations en base SQLite.
     * @param string $productRoute
     * @param array  $options Options de la ligne (déjà validées par le type de produit), pour le prix et le stock.
     * @return array|false
     */
    public static function getProductData(string $productRoute, array $options = [])
    {
        $db = self::getDatabase();

        // 1. Nettoyage préventif des paniers expirés
        $db->exec("DELETE FROM cart WHERE expires_at < datetime('now')");

        // 2. Produit et type de produit (blueprint `shop.*`, voir Product\ProductTypes) : prix et stock viennent du type,
        //    jamais directement des champs de la fiche.
        $resolved = Catalog::resolve($productRoute);
        if ($resolved === null) {
            return false;
        }
        [$page, $type] = $resolved;

        try {
            $price = $type->price($page, $options, Catalog::context($type, null, 1, $page));
            $stockPhysical = $type->stock($page, $options);
        } catch (\Throwable $e) {
            Grav::instance()['log']->error("[SimpleShop] Produit {$productRoute} : " . $e->getMessage());

            return false;
        }
        if ($price === null) {
            return false;   // pas vendable maintenant (hors vente, prévente sans prix…)
        }

        $data = [
            'product_price'    => $price->unitTtc->toFloat(),
            'product_price_ht' => $price->unitHt->toFloat(),
            'price'            => $price,
            'product_type'     => $type->name,
        ];

        // Stock illimité
        if ($stockPhysical === null) {
            return $data + ['product_virtual_stock' => -1];
        }

        // 3. Calcul de la somme des paniers actifs pour ce produit
        $stmt = $db->prepare("
            SELECT TOTAL(cl.quantity)
            FROM cart_line cl
            JOIN cart c ON cl.cart_id = c.cart_id
            WHERE cl.product_route = :route AND c.expires_at > datetime('now')
        ");
        $stmt->execute([':route' => $productRoute]);
        $reserved = (int)$stmt->fetchColumn();
        $stmt->closeCursor();

        return $data + ['product_virtual_stock' => max(0, $stockPhysical - $reserved)];
    }

    /**
     * Raccourci sémantique pour obtenir uniquement le stock virtuel
     */
    public static function getVirtualStock(string $productRoute): int
    {
        $data = self::getProductData($productRoute);
        if ($data === false) {
            return 0;
        }
        return $data['product_virtual_stock'];
    }

    /**
     * Raccourci sémantique pour obtenir uniquement le prix (null si non vendable)
     */
    public static function getProductPrice(string $productRoute): ?float
    {
        $data = self::getProductData($productRoute);
        if ($data === false) {
            return null;
        }
        return $data['product_price'];
    }

    /**
     * Décrémente le stock physique (`product_quantity`) d'un produit après une
     * commande validée.
     *
     * Le front-matter de la page produit est modifié EN PLACE, ligne
     * `product_quantity:` uniquement : on évite volontairement
     * `Page::save()`, qui re-sérialise tout l'en-tête YAML (ordre des clés,
     * guillemets, médias) et pourrait abîmer la fiche produit.
     *
     * @param string $productRoute Route Grav du produit (ex. "/livres/xxx").
     * @param int    $quantity     Quantité commandée.
     *
     * @return bool Vrai si le stock est à jour (ou illimité), faux si la
     *              décrémentation a échoué (déjà journalisé).
     */
    public static function decrementPhysicalStock(string $productRoute, int $quantity): bool
    {
        $grav = Grav::instance();

        try {
            $page = $grav['pages']->dispatch($productRoute);
            if (!$page || $page->filePath() === null || !is_file($page->filePath())) {
                throw new \RuntimeException("page produit introuvable pour '{$productRoute}'");
            }

            $lockDir = $grav['locator']->findResource('user://data/simple-shop', true, true);
            if (!is_dir($lockDir)) {
                mkdir($lockDir, 0755, true);
            }

            $result = self::decrementStockInFile($page->filePath(), $quantity, $lockDir . '/stock.lock');
            if ($result === null) {
                throw new \RuntimeException("ligne product_quantity absente ou illisible pour '{$productRoute}'");
            }

            if ($result['old'] < $quantity && $result['old'] !== -1) {
                $grav['log']->warning("[SimpleShop] Stock insuffisant pour '{$productRoute}' (stock {$result['old']}, commandé {$quantity}) : ramené à 0.");
            }

            return true;
        } catch (\Throwable $e) {
            $grav['log']->error('[SimpleShop] Déstockage impossible : ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Réécrit la ligne `product_quantity:` du front-matter d'un fichier de page.
     *
     * Opération sérialisée par un verrou de fichier dédié (le verrou ne peut
     * pas porter sur la page elle-même, remplacée par renommage) ; la valeur
     * est relue sous verrou, jamais reprise d'une lecture antérieure. L'écriture
     * passe par un fichier temporaire puis `rename()` : pas de page tronquée en
     * cas d'interruption.
     *
     * @return array{old:int,new:int}|null Null si la ligne est absente ; stock
     *         illimité (-1) : inchangé, old = new = -1.
     */
    public static function decrementStockInFile(string $file, int $quantity, string $lockFile): ?array
    {
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('verrou de stock indisponible');
        }

        try {
            $content = file_get_contents($file);
            if ($content === false || !preg_match('/\A(---\R)(.*?)(\R---\R?)/s', $content, $m)) {
                return null;
            }

            $front = $m[2];
            $pattern = '/^(product_quantity:[ \t]*)([\'"]?)(-?\d+)\2[ \t]*$/m';
            if (!preg_match($pattern, $front, $q)) {
                return null;
            }

            $old = (int) $q[3];
            if ($old === -1) {
                return ['old' => -1, 'new' => -1];
            }

            $new = max(0, $old - max(0, $quantity));
            $newFront = preg_replace($pattern, '${1}' . $q[2] . $new . $q[2], $front, 1);
            $newContent = $m[1] . $newFront . substr($content, strlen($m[1]) + strlen($front));

            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $newContent) === false) {
                throw new \RuntimeException("écriture impossible : {$tmp}");
            }
            @chmod($tmp, fileperms($file) & 0777);
            if (!rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException("remplacement impossible : {$file}");
            }

            return ['old' => $old, 'new' => $new];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Ouvre (et initialise si besoin) la connexion à la base SQLite du plugin.
     *
     * MIGRATION ADDITIVE (voir shop.sql) : le schéma SQL est exécuté à chaque
     * ouverture de connexion DONT le contenu (mtime) de `shop.sql` a changé
     * depuis la dernière application réussie, et non plus systématiquement à
     * CHAQUE appel comme précédemment.
     *
     * ⚠️ CORRECTIF (bug de performance/verrouillage SQLite, voir
     * docs/HISTORIQUE.md) : la version précédente ré-exécutait l'intégralité de
     * `shop.sql` (plusieurs `CREATE TABLE IF NOT EXISTS`) à CHAQUE appel de
     * cette méthode. Or `getDatabase()` est appelée de très nombreuses fois
     * par requête (`InventoryManager::getProductData()` pour chaque article
     * du panier, `CartStorage::touchCart()`, `CartStorage::revalidateStock()`,
     * `CartStorage::toOrder()`...). Même idempotente, chaque exécution ouvre
     * une transaction d'écriture SQLite, ce qui suffit à sérialiser
     * massivement les accès concurrents entre le tunnel d'achat (nombreuses
     * connexions par requête) et l'espace admin, provoquant des temps de
     * réponse très dégradés et des erreurs "database is locked".
     *
     * SOLUTION : un fichier marqueur `user://data/simple-shop/.shop-schema-applied`
     * mémorise le timestamp de modification (`mtime`) de `shop.sql`
     * effectivement appliqué avec succès. Tant que ce fichier n'a pas changé,
     * la ré-exécution du schéma est intégralement SAUTÉE. Dès que `shop.sql`
     * est modifié (ex. ajout d'une nouvelle table dans une version
     * ultérieure du plugin), son `mtime` change, et la migration est
     * automatiquement ré-appliquée au prochain appel — préservant le
     * comportement de migration additive automatique décrit ci-dessus, sans
     * en payer le coût à chaque requête.
     *
     * Un `PRAGMA busy_timeout` est également positionné en filet de sécurité
     * supplémentaire, pour absorber les contentions résiduelles inévitables
     * en cas d'écritures concurrentes légitimes (ex. deux clients validant
     * une commande au même instant).
     *
     * Le comportement de sécurité initial est conservé : si l'exécution du
     * schéma échoue lors de la toute première création de la base, le
     * fichier fraîchement créé est supprimé (pour ne pas laisser une base
     * SQLite vide/corrompue derrière soi) et l'exception est propagée. Si la
     * base existait déjà, une erreur d'exécution du schéma est journalisée
     * mais ne supprime PAS la base existante (qui contient des données de
     * production) ; dans ce cas, le marqueur n'est PAS mis à jour, afin que
     * la migration soit retentée au prochain appel.
     */
    /**
     * Colonnes ajoutées aux tables existantes (migration additive : jamais de suppression ni de modification).
     * Montants en centimes et taxe figée par ligne (14/10/2026) : les anciennes commandes gardent ces colonnes vides
     * et sont relues depuis leurs montants décimaux (voir Orders\OrderAmounts::fromStored()).
     */
    private const ADDED_COLUMNS = [
        'order' => [
            'source'             => "TEXT DEFAULT 'shop'",   // shop (boutique) | manual (saisie dans l'administration)
            'payload'            => 'TEXT',                  // commande manuelle : client, conditions, moyens, commentaire (JSON)
            'currency'           => 'TEXT',
            'total_cents'        => 'INTEGER',
            'shipping_ht_cents'  => 'INTEGER',
            'shipping_ttc_cents' => 'INTEGER',
            'shipping_tax_code'  => 'TEXT',
            'shipping_tax_bp'    => 'INTEGER',
            'shipping_taxes'     => 'TEXT',
        ],
        'order_item' => [
            'unit_ht_cents'  => 'INTEGER',
            'unit_ttc_cents' => 'INTEGER',
            'tax_code'       => 'TEXT',
            'tax_bp'         => 'INTEGER',
            'product_type'   => 'TEXT',
            'line_id'        => 'TEXT',
            'options'        => 'TEXT',
            'taxes'          => 'TEXT',
        ],
        // Documents (facture, acompte, avoir, demande de paiement…) : voir docs/CONCEPTION-FACTURATION.md, § 12.
        'invoice' => [
            'kind'               => "TEXT DEFAULT 'invoice'",
            'total_cents'        => 'INTEGER',
            'due_cents'          => 'INTEGER',
            'issued_at'          => 'DATETIME',
            'due_date'           => 'TEXT',
            'access_token'       => 'TEXT',
            'source_invoice_id'  => 'INTEGER',
            'related_invoice_id' => 'INTEGER',
            'payload'            => 'TEXT',
        ],
    ];

    /** Reprise des lignes de l'ancienne table cart_item (sans options) dans cart_line, puis vidage de cart_item. */
    private static function migrateCartLines(\PDO $db): void
    {
        $rows = $db->query('SELECT cart_id, product_route, quantity FROM cart_item')->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }
        $insert = $db->prepare('INSERT OR IGNORE INTO cart_line (cart_id, line_id, product_route, options, quantity) VALUES (:c, :l, :r, :o, :q)');
        foreach ($rows as $row) {
            $insert->execute([':c' => $row['cart_id'], ':l' => CartStorage::lineId((string) $row['product_route'], []),
                ':r' => $row['product_route'], ':o' => '{}', ':q' => (int) $row['quantity']]);
        }
        $db->exec('DELETE FROM cart_item');
    }

    /**
     * La table `invoice` imposait une seule facture par commande (`order_reference UNIQUE`) ; une commande peut désormais
     * avoir plusieurs documents (acomptes, facture finale, avoirs, demande de paiement). SQLite ne sait pas retirer une
     * contrainte : la table est reconstruite à l'identique, sans elle, une seule fois (données et identifiants conservés).
     */
    private static function allowSeveralDocumentsPerOrder(\PDO $db): void
    {
        $unique = false;
        foreach ($db->query("PRAGMA index_list('invoice')")->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }
            $cols = array_column($db->query("PRAGMA index_info('" . str_replace("'", "''", (string) $index['name']) . "')")->fetchAll(\PDO::FETCH_ASSOC), 'name');
            if ($cols === ['order_reference']) {
                $unique = true;
            }
        }
        if (!$unique) {
            return;
        }
        $columns = array_column($db->query("PRAGMA table_info('invoice')")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $list = implode(', ', array_map(static fn ($c) => '"' . $c . '"', $columns));
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->beginTransaction();
        try {
            $db->exec(self::tableWithoutUnique($db, 'invoice', 'invoice_new', 'order_reference'));
            $db->exec("INSERT INTO invoice_new ({$list}) SELECT {$list} FROM invoice");
            $db->exec('DROP TABLE invoice');
            $db->exec('ALTER TABLE invoice_new RENAME TO invoice');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_invoice_order ON invoice(order_reference)');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * CREATE TABLE de `$new` reprenant TOUTES les colonnes actuelles de `$table` (types, NOT NULL, valeurs par défaut, clé
     * primaire — y compris des colonnes inconnues de cette version du plugin), ses autres contraintes d'unicité, ses clés
     * étrangères, mais sans l'unicité de `$column`.
     */
    private static function tableWithoutUnique(\PDO $db, string $table, string $new, string $column): string
    {
        $q = static fn (string $n): string => '"' . str_replace('"', '""', $n) . '"';
        $cols = $db->query('PRAGMA table_info(' . $q($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $uniqueSingles = [];
        $uniqueGroups = [];
        foreach ($db->query('PRAGMA index_list(' . $q($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            if ((int) $index['unique'] !== 1 || ($index['origin'] ?? 'u') === 'pk') {
                continue;
            }
            $names = array_column($db->query('PRAGMA index_info(' . $q((string) $index['name']) . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            if ($names === [$column]) {
                continue;
            }
            if (count($names) === 1) {
                $uniqueSingles[$names[0]] = true;
            } else {
                $uniqueGroups[] = 'UNIQUE (' . implode(', ', array_map($q, $names)) . ')';
            }
        }
        $pk = array_values(array_filter($cols, static fn ($c) => (int) $c['pk'] > 0));
        usort($pk, static fn ($a, $b) => (int) $a['pk'] <=> (int) $b['pk']);
        $defs = [];
        foreach ($cols as $c) {
            $d = $q((string) $c['name']) . ($c['type'] !== '' ? ' ' . $c['type'] : '');
            if (count($pk) === 1 && (int) $c['pk'] === 1) {
                $d .= ' PRIMARY KEY';
            }
            if ((int) $c['notnull'] === 1) {
                $d .= ' NOT NULL';
            }
            if ($c['dflt_value'] !== null) {
                $d .= ' DEFAULT ' . $c['dflt_value'];
            }
            if (isset($uniqueSingles[$c['name']])) {
                $d .= ' UNIQUE';
            }
            $defs[] = $d;
        }
        if (count($pk) > 1) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn ($c) => $q((string) $c['name']), $pk)) . ')';
        }
        $defs = array_merge($defs, $uniqueGroups);
        $fks = [];
        foreach ($db->query('PRAGMA foreign_key_list(' . $q($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $fk) {
            $fks[(int) $fk['id']]['table'] = (string) $fk['table'];
            $fks[(int) $fk['id']]['from'][] = $q((string) $fk['from']);
            $fks[(int) $fk['id']]['to'][] = $q((string) $fk['to']);
            $fks[(int) $fk['id']]['on_delete'] = (string) $fk['on_delete'];
        }
        foreach ($fks as $fk) {
            $defs[] = 'FOREIGN KEY (' . implode(', ', $fk['from']) . ') REFERENCES ' . $q($fk['table']) . ' (' . implode(', ', $fk['to']) . ')'
                . ($fk['on_delete'] !== '' && $fk['on_delete'] !== 'NO ACTION' ? ' ON DELETE ' . $fk['on_delete'] : '');
        }

        return 'CREATE TABLE ' . $q($new) . " (\n    " . implode(",\n    ", $defs) . "\n)";
    }

    private static function addMissingColumns(\PDO $db): void
    {
        foreach (self::ADDED_COLUMNS as $table => $columns) {
            $existing = array_column($db->query('PRAGMA table_info("' . $table . '")')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            foreach ($columns as $name => $type) {
                if (!in_array($name, $existing, true)) {
                    $db->exec('ALTER TABLE "' . $table . '" ADD COLUMN ' . $name . ' ' . $type);
                }
            }
        }
    }

    public static function getDatabase(): \PDO
    {
        $grav = Grav::instance();
        $locator = $grav['locator'];
        
        $dbPath = $locator->findResource('user://data/simple-shop/shop.db', true, true);
        $sqlSource = $locator->findResource('plugins://simple-shop/shop.sql', true);
        
        $dbExists = file_exists($dbPath);

        if (!$dbExists) {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $db = new \PDO("sqlite:" . $dbPath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA foreign_keys = ON;");
        // Filet de sécurité contre les contentions résiduelles (écritures
        // concurrentes légitimes) : SQLite attendra jusqu'à 5s qu'un verrou
        // se libère avant de renvoyer "database is locked".
        $db->exec("PRAGMA busy_timeout = 5000;");

        if (!$sqlSource || !file_exists($sqlSource)) {
            if (!$dbExists) {
                $grav['log']->error("SimpleShop: Fichier de structure 'shop.sql' introuvable.");
                unset($db);
                @unlink($dbPath);
                throw new \RuntimeException("SimpleShop Initialization failed: 'shop.sql' missing.");
            }

            // Base déjà existante : on tolère l'absence du fichier (ne bloque
            // pas l'utilisation d'une base déjà initialisée), mais la
            // migration additive ci-dessus ne peut pas s'exécuter.
            $grav['log']->error("SimpleShop: Fichier de structure 'shop.sql' introuvable (migration additive ignorée).");

            return $db;
        }

        // --- Vérification du marqueur de dernière application du schéma ---
        $markerPath = dirname($dbPath) . '/.shop-schema-applied';
        // Marqueur = date de shop.sql + empreinte des colonnes ajoutées : une nouvelle colonne rejoue la migration
        // même si shop.sql n'a pas changé.
        $sqlMtime = filemtime($sqlSource) . '-' . substr(md5(json_encode(self::ADDED_COLUMNS)), 0, 8);
        $lastAppliedMtime = null;

        if (file_exists($markerPath)) {
            $rawMarker = @file_get_contents($markerPath);
            if ($rawMarker !== false && trim($rawMarker) !== '') {
                $lastAppliedMtime = trim($rawMarker);
            }
        }

        if ($dbExists && $lastAppliedMtime === $sqlMtime) {
            // shop.sql n'a pas changé depuis la dernière application réussie :
            // on saute intégralement la ré-exécution du schéma (voir doc
            // ci-dessus, correctif de performance/verrouillage).
            return $db;
        }

        // Une seule requête migre à la fois (les autres attendent, puis voient le repère à jour).
        $lock = @fopen(dirname($dbPath) . '/.shop-schema.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            $raw = is_file($markerPath) ? trim((string) @file_get_contents($markerPath)) : null;
            if ($dbExists && $raw === $sqlMtime) {
                return $db;
            }
            if ($dbExists) {
                self::backupBeforeMigration($dbPath);
            }

            return self::applySchema($db, $dbPath, $dbExists, $sqlSource, $markerPath, $sqlMtime);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Copie de la base avant une migration (`user://data/simple-shop/backups/shop-<date>.db`, 5 dernières gardées) : une
     * migration ratée ne doit jamais coûter les données.
     */
    private static function backupBeforeMigration(string $dbPath): void
    {
        $dir = dirname($dbPath) . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return;
        }
        $all = glob($dir . '/shop-*.db') ?: [];
        if ($all !== [] && max(array_map('filemtime', $all)) > time() - 3600) {
            return;   // migration qui échoue et se retente : une copie par heure suffit
        }
        @copy($dbPath, $dir . '/shop-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.db');
        $all = glob($dir . '/shop-*.db') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 5)) as $old) {
            @unlink($old);
        }
    }

    /** Schéma (shop.sql, IF NOT EXISTS partout) puis migrations additives ; repère écrit seulement si tout a réussi. */
    private static function applySchema(\PDO $db, string $dbPath, bool $dbExists, string $sqlSource, string $markerPath, string $sqlMtime): \PDO
    {
        $grav = Grav::instance();
        $sqlQueries = file_get_contents($sqlSource);
        try {
            $db->exec($sqlQueries);
        } catch (\PDOException $e) {
            if (!$dbExists) {
                unset($db);
                @unlink($dbPath);
                $grav['log']->error("SimpleShop: Erreur lors de l'exécution du schéma SQL : " . $e->getMessage());
                throw $e;
            }

            // Base déjà existante : on journalise sans supprimer la base de
            // production, et on renvoie malgré tout la connexion (qui reste
            // utilisable pour les tables déjà en place). Le marqueur n'est
            // volontairement PAS mis à jour ci-dessous (voir plus bas, hors
            // de ce catch), afin que la migration soit retentée au prochain appel.
            $grav['log']->error("SimpleShop: Erreur lors de la migration additive du schéma SQL : " . $e->getMessage());

            return $db;
        }

        // Colonnes ajoutées après coup (SQLite n'a pas de « ADD COLUMN IF NOT EXISTS ») : voir ADDED_COLUMNS.
        try {
            self::addMissingColumns($db);
            self::migrateCartLines($db);
            self::allowSeveralDocumentsPerOrder($db);
            \Grav\Plugin\SimpleShop\Payments\PaymentLedger::migrateLegacyPaidOrders($db);
        } catch (\PDOException $e) {
            $grav['log']->error("SimpleShop: Erreur lors de l'ajout de colonnes : " . $e->getMessage());

            return $db;
        }

        // Migration appliquée avec succès : on mémorise le mtime de shop.sql
        // pour que les prochains appels sautent cette exécution tant que le
        // fichier ne change pas.
        @file_put_contents($markerPath, $sqlMtime);

        return $db;
    }
}
