<?php

namespace Grav\Plugin\SimpleShop\Product;

use Grav\Plugin\SimpleShop\Pricing\Price;

/**
 * Conversion des fiches produit quand la base de saisie du site change (`taxes.prices_entered` : `ht` ↔ `ttc`).
 *
 * Une fiche sans `price_entered_as` est lue selon le réglage du site : passer le site de HT à TTC sans rien faire
 * ferait lire « 12.2749 » (HT) comme un prix TTC. La conversion réécrit donc, une seule fois, chaque prix des fiches
 * sans base explicite (champs `price_fields` du type de produit) dans la nouvelle base :
 *  - HT → TTC : le prix TTC payé jusque-là, à 2 décimales (12.2749 HT à 5,5 % → 12.95) — prix public inchangé ;
 *  - TTC → HT : le HT au centime (le TTC peut alors bouger d'un centime, voir tests/pricing.php).
 *
 * La base déjà appliquée aux fiches est mémorisée dans `user://data/simple-shop/.prices-basis`. Tant qu'elle égale le
 * réglage, rien n'est fait (une lecture de fichier par requête). **Repère absent** (nouveau site, ou dossier de données
 * supprimé avec la base) : les fiches sont réputées déjà saisies dans la base du réglage ; le repère est créé et RIEN n'est
 * converti — supprimer `user://data/simple-shop` ne doit jamais modifier les fiches (avant le 23/10/2026, un repère absent
 * valait `ht` et un site en TTC aurait vu ses prix reconvertis).
 * Écriture : ligne du front-matter réécrite en place (pas de Page::save(), qui reformaterait l'en-tête), sous le même
 * verrou que le déstockage, fichier temporaire + rename.
 */
final class PriceBasisMigration
{
    public const MARKER = '.prices-basis';

    /**
     * @param iterable $pages    Pages du site.
     * @param string   $dataDir  Dossier `user://data/simple-shop`.
     * @param array    $config   Configuration du plugin.
     * @param callable|null $log fn(string $level, string $message)
     *
     * @return array{from: string, to: string, pages: int, fields: int}|null null = rien à faire.
     */
    public static function run(iterable $pages, string $dataDir, array $config, ?callable $log = null): ?array
    {
        $target = (($config['taxes']['prices_entered'] ?? 'ht') === Price::TTC) ? Price::TTC : Price::HT;
        $marker = rtrim($dataDir, '/') . '/' . self::MARKER;
        if (!is_file($marker)) {
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0755, true);
            }
            @file_put_contents($marker, $target);
            if ($log) {
                $log('info', "[SimpleShop] Base de saisie des prix des fiches mémorisée : {$target} (aucune conversion).");
            }

            return null;
        }
        $current = trim((string) @file_get_contents($marker));
        if ($current === $target) {
            return null;
        }
        $lock = @fopen(rtrim($dataDir, '/') . '/stock.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;   // conversion en cours dans une autre requête
        }

        $result = ['from' => $current, 'to' => $target, 'pages' => 0, 'fields' => 0];
        try {
            // Relu sous verrou : une requête concurrente a pu terminer la conversion.
            $current = trim((string) @file_get_contents($marker));
            if ($current === $target || !in_array($current, [Price::HT, Price::TTC], true)) {
                return null;
            }
            foreach ($pages as $page) {
                $type = ProductTypes::forPage($page);
                $file = $type ? $page->filePath() : null;
                if (!$type || !$file || !is_file($file)) {
                    continue;
                }
                $header = (array) $page->header();
                if (in_array($header['price_entered_as'] ?? null, [Price::HT, Price::TTC], true)) {
                    continue;   // base explicite sur la fiche : rien à convertir
                }
                $ctx = Catalog::context($type, $config, 1, $page);
                $ctx = new PriceContext($ctx->currency, $ctx->tax, $current, $ctx->date, 1, $config);
                $changes = [];
                foreach ($type->priceFields as $field) {
                    $price = GenericProduct::priceFromField($header, $field, $ctx);
                    if ($price !== null) {
                        $changes[$field] = ($target === Price::TTC ? $price->unitTtc : $price->unitHt)->toDecimalString();
                    }
                }
                if ($changes !== [] && self::rewrite($file, $changes)) {
                    // En mémoire aussi : la requête en cours lit déjà la nouvelle base.
                    if (method_exists($page, 'modifyHeader')) {
                        foreach ($changes as $field => $value) {
                            $page->modifyHeader($field, $value);
                        }
                    }
                    $result['pages']++;
                    $result['fields'] += count($changes);
                } elseif ($changes !== [] && $log) {
                    $log('warning', "[SimpleShop] Conversion {$current} → {$target} impossible pour {$file}");
                }
            }
            file_put_contents($marker, $target);
            if ($log) {
                $log('info', "[SimpleShop] Prix des fiches convertis de {$current} en {$target} : {$result['fields']} prix sur {$result['pages']} fiches.");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    /** Réécrit les lignes `champ: valeur` du front-matter (valeurs entre guillemets simples). */
    public static function rewrite(string $file, array $changes): bool
    {
        $content = file_get_contents($file);
        if ($content === false || !preg_match('/\A(---\R)(.*?)(\R---\R?)/s', $content, $m)) {
            return false;
        }
        $front = $m[2];
        foreach ($changes as $field => $value) {
            $pattern = '/^(' . preg_quote($field, '/') . ':[ \t]*)([\'"]?)([0-9.,]+)\2[ \t]*$/m';
            if (!preg_match($pattern, $front)) {
                return false;
            }
            $front = preg_replace($pattern, '${1}\'' . $value . '\'', $front, 1);
        }
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $m[1] . $front . substr($content, strlen($m[1]) + strlen($m[2]))) === false) {
            return false;
        }

        return rename($tmp, $file);
    }
}
