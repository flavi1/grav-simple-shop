<?php

namespace Grav\Plugin\SimpleShop\Catalog;

use Grav\Plugin\SimpleShop\Product\ProductType;
use Grav\Plugin\SimpleShop\Product\ProductTypes;

/**
 * Tableau d'édition rapide des fiches d'un type de produit (Admin2 > Ventes > Catalogue).
 *
 * Les colonnes sont déclarées par le blueprint du type (bloc `shop.admin_table.columns` : un nom de champ, ou une liste de
 * champs empilés dans une même cellule). Chaque champ est décrit par SA définition dans le blueprint (`header.<nom>` :
 * type, libellé, `validate`, attributs, options…) : une contrainte ajoutée au blueprint vaut aussitôt pour le tableau
 * (attributs HTML côté navigateur, validation de Grav côté serveur). Rien de propre à un type de produit ici.
 *
 * Écriture : seuls les champs déclarés, seulement ceux qui ont changé ; la valeur affichée au chargement accompagne la
 * nouvelle et doit toujours être celle du fichier (sinon conflit : une commande a pu déstocker entre-temps). Sous le
 * verrou du déstockage (`stock.lock`), fichier temporaire + `rename`, en-tête réécrit comme le fait Admin2 (texte).
 */
final class CatalogTable
{
    /** Types de champs éditables dans une cellule. */
    public const SUPPORTED = ['text', 'textarea', 'number', 'date', 'email', 'url', 'select', 'radio', 'toggle', 'checkbox'];

    /** @return array<string, ProductType> types de produits qui déclarent un tableau */
    public static function types(): array
    {
        return array_filter(ProductTypes::all(), static fn (ProductType $t) => !empty($t->adminTable['columns']) && is_array($t->adminTable['columns']));
    }

    /**
     * Colonnes du tableau d'un type, champs décrits par le blueprint.
     *
     * @param callable $fieldDef fn(string $name): ?array — définition du champ `header.<name>` (blueprint résolu).
     * @param string   $basis    Base de saisie des prix du site (`ht` | `ttc`), pour le libellé des champs de prix.
     *
     * @return array{columns: list<array{fields: list<array>}>, errors: string[]}
     */
    public static function columns(ProductType $type, callable $fieldDef, string $basis): array
    {
        $columns = [];
        $errors = [];
        foreach ((array) ($type->adminTable['columns'] ?? []) as $spec) {
            $names = is_array($spec) ? array_values($spec) : [$spec];
            $fields = [];
            foreach ($names as $name) {
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    $errors[] = 'Nom de champ invalide : ' . json_encode($name);
                    continue;
                }
                $def = $fieldDef($name);
                if (!is_array($def)) {
                    $errors[] = "Champ « {$name} » absent du blueprint « {$type->name} ».";
                    continue;
                }
                $kind = (string) ($def['type'] ?? 'text');
                if (!in_array($kind, self::SUPPORTED, true)) {
                    $errors[] = "Champ « {$name} » : type « {$kind} » non éditable dans le tableau.";
                    continue;
                }
                $fields[] = self::describe($name, $def, in_array($name, $type->priceFields, true) ? $basis : null);
            }
            if ($fields !== []) {
                $columns[] = ['fields' => $fields];
            }
        }

        return ['columns' => $columns, 'errors' => $errors];
    }

    /** Ce que l'écran doit savoir d'un champ (le blueprint reste la référence : `definition` sert à la validation). */
    private static function describe(string $name, array $def, ?string $priceBasis): array
    {
        $validate = is_array($def['validate'] ?? null) ? $def['validate'] : [];
        $attributes = is_array($def['attributes'] ?? null) ? $def['attributes'] : [];
        $pattern = $validate['pattern'] ?? $attributes['pattern'] ?? null;
        $options = [];
        foreach ((array) ($def['options'] ?? []) as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => is_scalar($label) ? (string) $label : (string) $value];
        }
        $type = (string) ($def['type'] ?? 'text');

        return [
            'name'        => $name,
            'label'       => (string) ($def['label'] ?? $name),
            'type'        => $type === 'radio' ? 'select' : ($type === 'checkbox' ? 'toggle' : $type),
            'required'    => (bool) ($validate['required'] ?? false),
            'pattern'     => is_string($pattern) && $pattern !== '' ? self::htmlPattern($pattern) : null,
            'min'         => $validate['min'] ?? $def['min'] ?? null,
            'max'         => $validate['max'] ?? $def['max'] ?? null,
            'step'        => $validate['step'] ?? $def['step'] ?? null,
            'inputmode'   => isset($attributes['inputmode']) ? (string) $attributes['inputmode'] : null,
            'placeholder' => isset($def['placeholder']) ? (string) $def['placeholder'] : null,
            'append'      => isset($def['append']) ? (string) $def['append'] : null,
            'options'     => $options,
            'default'     => isset($def['default']) && is_scalar($def['default']) ? (string) $def['default'] : null,
            'price_basis' => $priceBasis,
            'definition'  => $def + ['name' => 'header.' . $name],
        ];
    }

    /** Motif d'une règle de Grav (PCRE, ancres facultatives) → attribut HTML `pattern` (toujours ancré par le navigateur). */
    private static function htmlPattern(string $pattern): string
    {
        $p = $pattern;
        if (str_starts_with($p, '^')) {
            $p = substr($p, 1);
        }
        if (str_ends_with($p, '$') && !str_ends_with($p, '\\$')) {
            $p = substr($p, 0, -1);
        }

        return $p;
    }

    /** Valeur d'en-tête affichée dans une cellule (texte, comme Admin2 l'enregistre). */
    public static function cellValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Lignes du tableau : une par fiche du type (gabarit = nom du type), triées par titre.
     *
     * @return list<array{route: string, title: string, published: bool, price_entered_as: ?string, values: array<string, string>}>
     */
    public static function rows(iterable $pages, ProductType $type, array $columns): array
    {
        $rows = [];
        foreach ($pages as $page) {
            if ((string) $page->template() !== $type->name || !$page->filePath() || !is_file($page->filePath())) {
                continue;
            }
            $h = (array) $page->header();
            $values = [];
            foreach ($columns as $col) {
                foreach ($col['fields'] as $f) {
                    $values[$f['name']] = self::cellValue($h[$f['name']] ?? null);
                }
            }
            $basis = $h['price_entered_as'] ?? null;
            $rows[] = [
                'route'            => (string) $page->route(),
                'title'            => (string) $page->title(),
                'published'        => method_exists($page, 'published') ? (bool) $page->published() : true,
                'price_entered_as' => in_array($basis, ['ht', 'ttc'], true) ? $basis : null,
                'values'           => $values,
            ];
        }
        usort($rows, static fn ($a, $b) => strnatcasecmp(self::fold($a['title']), self::fold($b['title'])));

        return $rows;
    }

    private static function fold(string $s): string
    {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $s) : false;

        return strtolower($t !== false ? $t : $s);
    }

    /**
     * Enregistre les champs modifiés d'une fiche.
     *
     * @param array<string, string> $changes  champ => nouvelle valeur
     * @param array<string, string> $original champ => valeur affichée au chargement (contrôle de conflit)
     * @param callable              $validate fn(string $value, array $definition): string[] — messages d'erreur (Grav)
     *
     * @return array{ok: bool, errors: array<string, string[]>, conflicts: array<string, string>, values: array<string, string>}
     */
    public static function save(string $file, array $changes, array $original, array $columns, callable $validate, string $lockFile): array
    {
        $fields = [];
        foreach ($columns as $col) {
            foreach ($col['fields'] as $f) {
                $fields[$f['name']] = $f;
            }
        }
        $errors = [];
        $clean = [];
        foreach ($changes as $name => $value) {
            if (!isset($fields[$name])) {
                $errors[(string) $name][] = 'Champ non modifiable ici.';
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                $errors[$name][] = 'Valeur invalide.';
                continue;
            }
            $value = trim((string) $value);
            $f = $fields[$name];
            if ($f['options'] !== [] && $value !== '' && !in_array($value, array_column($f['options'], 'value'), true)) {
                $errors[$name][] = 'Valeur hors de la liste.';
                continue;
            }
            $messages = $validate($value, $f['definition']);
            if ($messages) {
                $errors[$name] = array_values(array_unique(array_map('strval', $messages)));
                continue;
            }
            $clean[$name] = $value;
        }
        if ($errors || $clean === []) {
            return ['ok' => $errors === [], 'errors' => $errors, 'conflicts' => [], 'values' => []];
        }

        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('verrou de stock indisponible');
        }
        try {
            $content = file_get_contents($file);
            if ($content === false || !preg_match('/\A---\R(.*?)\R---\R?/s', $content, $m)) {
                throw new \RuntimeException('en-tête de la fiche illisible');
            }
            $header = (array) \Grav\Common\Yaml::parse($m[1]);
            $conflicts = [];
            foreach ($clean as $name => $value) {
                $current = self::cellValue($header[$name] ?? null);
                if (array_key_exists($name, $original) && trim((string) $original[$name]) !== $current) {
                    $conflicts[$name] = $current;
                }
            }
            if ($conflicts) {
                return ['ok' => false, 'errors' => [], 'conflicts' => $conflicts, 'values' => []];
            }
            foreach ($clean as $name => $value) {
                $header[$name] = $fields[$name]['type'] === 'toggle' ? ($value === '1') : $value;
            }
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, "---\n" . \Grav\Common\Yaml::dump($header, 20) . "---\n" . substr($content, strlen($m[0]))) === false) {
                throw new \RuntimeException('écriture impossible');
            }
            @chmod($tmp, fileperms($file) & 0777);
            if (!rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('remplacement de la fiche impossible');
            }
            $values = [];
            foreach ($fields as $name => $f) {
                $values[$name] = self::cellValue($header[$name] ?? null);
            }

            return ['ok' => true, 'errors' => [], 'conflicts' => [], 'values' => $values];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
