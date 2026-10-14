<?php
// InventoryManager.php

namespace Grav\Plugin\SimpleShop\Inventory;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Services\TaxManager;

class InventoryManager
{
    /**
     * Calcule le stock virtuel et récupère le prix à partir du fichier .md du produit 
     * et des réservations en base SQLite.
     * @param string $productRoute
     * @return array|false
     */
    public static function getProductData(string $productRoute)
    {
        $grav = Grav::instance();
        $db = self::getDatabase();
        
        // 1. Nettoyage préventif des paniers expirés
        $db->exec("DELETE FROM cart WHERE expires_at < datetime('now')");

        // 2. Récupération de la page Grav
        $grav['pages']->init();
        $page = $grav['pages']->dispatch($productRoute);

        if (!$page || !$page->published()) {
            return false;
        }

        $header = $page->header();

        // Sécurité : Si la modalité de vente n'est pas "Vendre ici", le produit n'est pas achetable
        $salesMethod = $header->sales_method ?? 'sell_here';
        if ($salesMethod !== 'sell_here') {
            return false;
        }

        // Détermination du prix de base
        $price = isset($header->product_price) ? (float)$header->product_price : 0.00;


		// Règle Prévente : Si la date de publication est dans le futur
		if (isset($header->publication_date) && !empty($header->publication_date)) {
			$pubDate = strtotime($header->publication_date);
			$today = strtotime(date('Y-m-d'));

			if ($pubDate > $today) {
				// Si un prix de prévente existe, on l'applique. Sinon, vente impossible.
				if (isset($header->product_presale_price) && $header->product_presale_price !== '') {
					$price = (float)$header->product_presale_price;
				} else {
					return false;
				}
			}
		}

        // Les prix des fiches produit sont saisis hors taxe (TaxManager) : tout
        // le reste de la boutique (panier, paiement, commande) travaille en TTC.
        $priceHt = $price;
        $price = TaxManager::toTtc($price);

        // Alignement du stock physique selon book.yaml
        $stockPhysical = isset($header->product_quantity) ? (int)$header->product_quantity : 0;

        // Gestion du stock infini (-1)
        if ($stockPhysical === -1) {
            return [
                'product_price'         => $price,
                'product_price_ht'      => $priceHt,
                'product_virtual_stock' => -1
            ];
        }

        // 3. Calcul de la somme des paniers actifs pour ce produit
        $stmt = $db->prepare("
            SELECT TOTAL(ci.quantity) 
            FROM cart_item ci
            JOIN cart c ON ci.cart_id = c.cart_id
            WHERE ci.product_route = :route AND c.expires_at > datetime('now')
        ");
        $stmt->execute([':route' => $productRoute]);
        $reserved = (int)$stmt->fetchColumn();
        $stmt->closeCursor();

        return [
            'product_price'         => $price,
            'product_price_ht'      => $priceHt,
            'product_virtual_stock' => max(0, $stockPhysical - $reserved)
        ];
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
     * NOTES-TODO.md) : la version précédente ré-exécutait l'intégralité de
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
        $sqlMtime = (int) filemtime($sqlSource);
        $lastAppliedMtime = null;

        if (file_exists($markerPath)) {
            $rawMarker = @file_get_contents($markerPath);
            if ($rawMarker !== false && trim($rawMarker) !== '') {
                $lastAppliedMtime = (int) trim($rawMarker);
            }
        }

        if ($dbExists && $lastAppliedMtime === $sqlMtime) {
            // shop.sql n'a pas changé depuis la dernière application réussie :
            // on saute intégralement la ré-exécution du schéma (voir doc
            // ci-dessus, correctif de performance/verrouillage).
            return $db;
        }

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

        // Migration appliquée avec succès : on mémorise le mtime de shop.sql
        // pour que les prochains appels sautent cette exécution tant que le
        // fichier ne change pas.
        @file_put_contents($markerPath, (string) $sqlMtime);

        return $db;
    }
}
