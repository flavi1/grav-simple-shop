<?php

namespace Grav\Plugin\SimpleShop\Product;

/**
 * Registre des types de produits, découverts dans les blueprints de pages (bloc `shop:` avec `price_calculation`).
 *
 * - Un type = un blueprint (`book.yaml` → type « book ») ; une page est de ce type si son gabarit porte ce nom.
 * - Repli : une page dont le gabarit n'est pas un type déclaré mais qui a un `product_price` est un produit
 *   **générique** (type « default ») — compatibilité avec un site qui ne déclare rien.
 * - Cache : la liste est gardée tant que les fichiers de blueprints (chemins + dates de modification) ne changent pas.
 *
 * Indépendant de Grav pour les tests : les dossiers de blueprints et le cache sont injectés.
 */
final class ProductTypes
{
    public const FALLBACK = 'default';

    /** @var array<string, ProductType>|null */
    private static ?array $types = null;
    /** @var string[] */
    private static array $errors = [];

    /**
     * @param string[]      $dirs  Dossiers de blueprints de pages.
     * @param callable|null $cache fn(string $key, callable $compute): array — cache externe (Grav), facultatif.
     *
     * @return array<string, ProductType>
     */
    public static function discover(array $dirs, ?callable $cache = null): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            foreach (is_dir($dir) ? (glob(rtrim($dir, '/') . '/*.yaml') ?: []) : [] as $f) {
                $files[basename($f, '.yaml')] ??= $f;   // premier dossier prioritaire (comme les flux Grav)
            }
        }
        ksort($files);
        $key = 'simple-shop-product-types-' . md5(json_encode(array_map(static fn ($f) => [$f, @filemtime($f)], $files)));
        $compute = static function () use ($files): array {
            $defs = [];
            foreach ($files as $name => $file) {
                try {
                    $yaml = \Symfony\Component\Yaml\Yaml::parseFile($file);
                } catch (\Throwable $e) {
                    continue;
                }
                $shop = is_array($yaml['shop'] ?? null) ? $yaml['shop'] : null;
                if ($shop !== null && !empty($shop['price_calculation'])) {
                    $defs[$name] = $shop;
                }
            }

            return $defs;
        };
        $defs = $cache ? $cache($key, $compute) : $compute();

        self::$errors = [];
        self::$types = [];
        foreach ($defs as $name => $shop) {
            $type = self::build((string) $name, $shop);
            if ($type !== null) {
                self::$types[$name] = $type;
            }
        }

        return self::$types;
    }

    /** Déclare les types directement (tests, ou plugin qui fournit ses types sans blueprint). */
    public static function set(array $definitions): void
    {
        self::$errors = [];
        self::$types = [];
        foreach ($definitions as $name => $shop) {
            $type = self::build((string) $name, (array) $shop);
            if ($type !== null) {
                self::$types[$name] = $type;
            }
        }
    }

    public static function reset(): void
    {
        self::$types = null;
        self::$errors = [];
    }

    /** @return array<string, ProductType> */
    public static function all(): array
    {
        if (self::$types === null) {
            self::discover(self::gravDirs(), self::gravCache());
        }

        return self::$types;
    }

    /** @return string[] Erreurs de déclaration (méthode introuvable…), pour le journal et l'administration. */
    public static function errors(): array
    {
        return self::$errors;
    }

    /** Type d'une page, ou null si elle ne se vend pas. */
    public static function forPage($page): ?ProductType
    {
        if (!$page) {
            return null;
        }
        $types = self::all();
        $template = (string) $page->template();
        if (isset($types[$template])) {
            return $types[$template];
        }
        $h = (array) $page->header();
        if (isset($h['product_price']) && $h['product_price'] !== '') {
            return new ProductType(self::FALLBACK);
        }

        return null;
    }

    private static function build(string $name, array $shop): ?ProductType
    {
        $handlers = [];
        foreach (ProductType::HANDLERS as $key) {
            if (empty($shop[$key])) {
                continue;
            }
            $ref = (string) $shop[$key];
            if (!preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*$/', $ref) || !is_callable($ref)) {
                self::$errors[] = "Type de produit « {$name} » : {$key} « {$ref} » introuvable (méthode statique « Classe::méthode » attendue).";
                if ($key === 'price_calculation') {
                    return null;
                }
                continue;
            }
            $handlers[$key] = $ref;
        }

        $fields = array_values(array_filter((array) ($shop['price_fields'] ?? ['product_price']),
            static fn ($f) => is_string($f) && preg_match('/^[A-Za-z0-9_]+$/', $f)));

        return new ProductType($name, $handlers, isset($shop['tax']) && $shop['tax'] !== '' ? (string) $shop['tax'] : null, $fields ?: ['product_price'],
            is_array($shop['admin_table'] ?? null) ? $shop['admin_table'] : []);
    }

    /** Dossiers de blueprints de pages de Grav (flux `blueprints://pages` et blueprints du thème actif). */
    private static function gravDirs(): array
    {
        $dirs = [];
        try {
            $locator = \Grav\Common\Grav::instance()['locator'];
            $theme = $locator->findResource('theme://blueprints');
            if ($theme) {
                $dirs[] = $theme;
            }
            foreach ((array) $locator->findResources('blueprints://pages') as $d) {
                $dirs[] = $d;
            }
        } catch (\Throwable $e) {
            // hors Grav
        }

        return $dirs;
    }

    private static function gravCache(): ?callable
    {
        try {
            $cache = \Grav\Common\Grav::instance()['cache'];
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_object($cache) || !method_exists($cache, 'fetch') || !method_exists($cache, 'save')) {
            return null;
        }

        return static function (string $key, callable $compute) use ($cache): array {
            $hit = $cache->fetch($key);
            if (is_array($hit)) {
                return $hit;
            }
            $value = $compute();
            $cache->save($key, $value);

            return $value;
        };
    }
}
