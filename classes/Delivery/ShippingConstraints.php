<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Contraintes globales sur le prix de livraison (bloc `constraints:` de carriers.yaml).
 *
 * Une contrainte est un ensemble de règles « si conditions remplies, alors … », appliqué à tous les modes
 * (sauf ceux qu'elle exclut), après le calcul du prix du mode (grille ou forfait, marge, TVA, « offert dès »).
 * Elle sert aussi bien à une loi (ex. plancher des frais de livraison de livres) qu'à une offre commerciale
 * (ex. livraison à 0,01 € dès 50 €). Rien n'est propre à un type de produit : les types concernés sont un réglage.
 *
 *   constraints:
 *     - name: "Loi Darcos"
 *       product_types: [book]        # vide = tous les articles ; type = `product_type` de la fiche, sinon son gabarit
 *       except_modes: [retrait_atelier]
 *       rules:                       # dans une contrainte, la première règle qui correspond s'applique
 *         - { max_amount: 34.99, type: minimum, value: 3.00, comment: "…" }
 *         - { min_amount: 35, type: minimum, value: 0.01 }
 *
 * Conditions d'une règle (bornes incluses, absentes = sans limite) : `min_weight`/`max_weight` (g, poids du colis,
 * emballage compris) et `min_amount`/`max_amount` (€ TTC des articles concernés). Effet : `type` = minimum (le prix
 * ne descend pas sous `value`), maximum (ne dépasse pas) ou fixed (prix imposé), `value` en € TTC.
 * Les contraintes s'appliquent dans l'ordre de la liste. Une contrainte limitée à des types d'articles ne
 * s'applique que si le panier en contient.
 *
 * Classe sans dépendance à Grav : testable en ligne de commande.
 */
final class ShippingConstraints
{
    public const TYPES = ['minimum', 'maximum', 'fixed'];

    private const MAX_CONSTRAINTS = 50;
    private const MAX_RULES = 100;

    /**
     * Normalise une liste lue dans carriers.yaml (valeurs sûres, sans erreur).
     *
     * @return array<int, array{name: string, product_types: string[], except_modes: string[], rules: array[]}>
     */
    public static function normalize($list): array
    {
        $out = [];
        foreach (is_array($list) ? array_values($list) : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $rules = [];
            foreach (is_array($c['rules'] ?? null) ? array_values($c['rules']) : [] as $r) {
                if (!is_array($r) || !in_array($r['type'] ?? null, self::TYPES, true) || !is_numeric($r['value'] ?? null)) {
                    continue;
                }
                $rules[] = [
                    'min_weight' => self::num($r['min_weight'] ?? null),
                    'max_weight' => self::num($r['max_weight'] ?? null),
                    'min_amount' => self::num($r['min_amount'] ?? null),
                    'max_amount' => self::num($r['max_amount'] ?? null),
                    'type'       => $r['type'],
                    'value'      => max(0.0, (float) $r['value']),
                    'comment'    => trim((string) ($r['comment'] ?? '')),
                ];
            }
            $out[] = [
                'name'          => trim((string) ($c['name'] ?? '')) ?: 'Contrainte ' . (count($out) + 1),
                'product_types' => self::strings($c['product_types'] ?? []),
                'except_modes'  => self::strings($c['except_modes'] ?? []),
                'rules'         => $rules,
            ];
        }

        return $out;
    }

    /**
     * Vérifie le bloc `constraints` d'un carriers.yaml (édition YAML avancée).
     *
     * @return string[] Erreurs en français.
     */
    public static function validate($list): array
    {
        if ($list === null) {
            return [];
        }
        if (!is_array($list) || ($list !== [] && array_keys($list) !== range(0, count($list) - 1))) {
            return ['constraints doit être une liste (- name: …).'];
        }

        return self::sanitize($list)[1];
    }

    /**
     * Valide et normalise une liste reçue de l'administration (virgule décimale acceptée).
     *
     * @return array{0: array, 1: string[]} [contraintes, erreurs]
     */
    public static function sanitize(array $list): array
    {
        $errors = [];
        $clean = [];
        $list = array_values(array_filter($list, 'is_array'));
        if (count($list) > self::MAX_CONSTRAINTS) {
            $errors[] = 'Trop de contraintes (maximum ' . self::MAX_CONSTRAINTS . ').';
            $list = array_slice($list, 0, self::MAX_CONSTRAINTS);
        }

        foreach ($list as $i => $c) {
            $where = 'Contrainte ' . ($i + 1);
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '') {
                $errors[] = "{$where} : le nom est obligatoire.";
            } elseif (mb_strlen($name) > 80) {
                $errors[] = "{$where} : nom trop long (80 caractères au plus).";
            }
            $where = $name !== '' ? "« {$name} »" : $where;

            $number = static function ($v, string $label, float $max) use (&$errors): ?float {
                if ($v === null || $v === '') {
                    return null;
                }
                if (is_string($v)) {
                    $v = str_replace(',', '.', trim($v));
                }
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
                    $errors[] = "{$label} : nombre positif attendu (au plus {$max}).";

                    return null;
                }

                return round((float) $v, 4);
            };

            $rules = [];
            $rawRules = array_values(array_filter((array) ($c['rules'] ?? []), 'is_array'));
            if ($rawRules === []) {
                $errors[] = "{$where} : ajoutez au moins une règle.";
            }
            if (count($rawRules) > self::MAX_RULES) {
                $errors[] = "{$where} : trop de règles (maximum " . self::MAX_RULES . ').';
                $rawRules = array_slice($rawRules, 0, self::MAX_RULES);
            }
            foreach ($rawRules as $j => $r) {
                $rw = "{$where}, règle " . ($j + 1);
                $type = (string) ($r['type'] ?? '');
                if (!in_array($type, self::TYPES, true)) {
                    $errors[] = "{$rw} : type inconnu (minimum, maximum ou prix imposé).";
                }
                $value = $number($r['value'] ?? null, "{$rw}, valeur", 10000);
                if ($value === null && !in_array("{$rw}, valeur : nombre positif attendu (au plus 10000).", $errors, true)) {
                    $errors[] = "{$rw} : la valeur est obligatoire.";
                }
                $rule = [
                    'min_weight' => $number($r['min_weight'] ?? null, "{$rw}, poids minimal", 10000000),
                    'max_weight' => $number($r['max_weight'] ?? null, "{$rw}, poids maximal", 10000000),
                    'min_amount' => $number($r['min_amount'] ?? null, "{$rw}, montant minimal", 10000000),
                    'max_amount' => $number($r['max_amount'] ?? null, "{$rw}, montant maximal", 10000000),
                    'type'       => $type,
                    'value'      => $value ?? 0.0,
                    'comment'    => trim((string) ($r['comment'] ?? '')),
                ];
                if (mb_strlen($rule['comment']) > 300) {
                    $errors[] = "{$rw} : commentaire trop long (300 caractères au plus).";
                }
                if ($rule['min_weight'] !== null && $rule['max_weight'] !== null && $rule['min_weight'] > $rule['max_weight']) {
                    $errors[] = "{$rw} : le poids minimal dépasse le poids maximal.";
                }
                if ($rule['min_amount'] !== null && $rule['max_amount'] !== null && $rule['min_amount'] > $rule['max_amount']) {
                    $errors[] = "{$rw} : le montant minimal dépasse le montant maximal.";
                }
                $rules[] = $rule;
            }

            foreach (['product_types', 'except_modes'] as $field) {
                foreach ((array) ($c[$field] ?? []) as $v) {
                    if (!is_string($v) || !preg_match('/^[a-z0-9_.-]{1,60}$/i', $v)) {
                        $errors[] = "{$where} : valeur invalide dans {$field}.";
                    }
                }
            }

            $clean[] = [
                'name'          => $name,
                'product_types' => self::strings($c['product_types'] ?? []),
                'except_modes'  => self::strings($c['except_modes'] ?? []),
                'rules'         => $rules,
            ];
        }

        return [$clean, $errors];
    }

    /** Forme à écrire dans carriers.yaml (bornes vides omises). */
    public static function toStorage(array $clean): array
    {
        $out = [];
        foreach ($clean as $c) {
            $entry = ['name' => $c['name']];
            if ($c['product_types'] !== []) {
                $entry['product_types'] = $c['product_types'];
            }
            if ($c['except_modes'] !== []) {
                $entry['except_modes'] = $c['except_modes'];
            }
            $entry['rules'] = array_map(static function (array $r): array {
                $rule = [];
                foreach (['min_weight', 'max_weight', 'min_amount', 'max_amount'] as $k) {
                    if ($r[$k] !== null) {
                        $rule[$k] = $r[$k];
                    }
                }
                $rule['type'] = $r['type'];
                $rule['value'] = $r['value'];
                if ($r['comment'] !== '') {
                    $rule['comment'] = $r['comment'];
                }

                return $rule;
            }, $c['rules']);
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Applique les contraintes au prix TTC d'un mode.
     *
     * @param array $metrics weight (g), subtotal (€ TTC, tous articles), subtotals (€ TTC par type d'article).
     *
     * @return array{0: float, 1: array[]} [prix, contraintes appliquées : name, type, value, comment, before, after]
     */
    public static function apply(float $price, string $modeKey, array $metrics, array $constraints): array
    {
        $applied = [];
        foreach ($constraints as $c) {
            if (in_array($modeKey, $c['except_modes'] ?? [], true)) {
                continue;
            }
            $amount = self::amountFor($c, $metrics);
            if ($amount === null) {
                continue;
            }
            foreach ($c['rules'] ?? [] as $r) {
                if (!self::matches($r, (float) ($metrics['weight'] ?? 0), $amount)) {
                    continue;
                }
                $before = $price;
                $value = (float) $r['value'];
                $price = $r['type'] === 'minimum' ? max($price, $value) : ($r['type'] === 'maximum' ? min($price, $value) : $value);
                $applied[] = [
                    'name'    => $c['name'],
                    'type'    => $r['type'],
                    'value'   => $value,
                    'comment' => $r['comment'] ?? '',
                    'before'  => round($before, 2),
                    'after'   => round($price, 2),
                ];
                break;
            }
        }

        return [round($price, 2), $applied];
    }

    /**
     * Montant TTC des articles concernés ; null si la contrainte vise des types absents du panier.
     */
    public static function amountFor(array $constraint, array $metrics): ?float
    {
        $types = $constraint['product_types'] ?? [];
        if ($types === []) {
            return (float) ($metrics['subtotal'] ?? 0);
        }
        $subtotals = (array) ($metrics['subtotals'] ?? []);
        $amount = 0.0;
        $found = false;
        foreach ($types as $t) {
            if (array_key_exists($t, $subtotals)) {
                $found = true;
                $amount += (float) $subtotals[$t];
            }
        }

        return $found ? round($amount, 2) : null;
    }

    private static function matches(array $r, float $weight, float $amount): bool
    {
        return ($r['min_weight'] === null || $weight >= $r['min_weight'])
            && ($r['max_weight'] === null || $weight <= $r['max_weight'])
            && ($r['min_amount'] === null || $amount >= $r['min_amount'] - 1e-9)
            && ($r['max_amount'] === null || $amount <= $r['max_amount'] + 1e-9);
    }

    /**
     * Ancienne configuration (`delivery.book_law` + `legal_floor: false` sur des modes) convertie en contrainte,
     * pour les sites dont carriers.yaml n'a pas encore de bloc `constraints`. Rien si `book_law` est absent.
     *
     * @param array $config   Configuration du plugin simple-shop.
     * @param array $carriers Contenu brut de `carriers:`.
     */
    public static function fromLegacy(array $config, array $carriers): array
    {
        if (!isset($config['delivery']['book_law']) || !is_array($config['delivery']['book_law'])) {
            return [];
        }
        $law = LegalShippingFloor::settings($config);
        if (!$law['enabled']) {
            return [];
        }
        $except = [];
        foreach ($carriers as $key => $c) {
            if (is_array($c) && ($c['legal_floor'] ?? true) === false) {
                $except[] = (string) $key;
            }
        }

        return self::normalize([[
            'name'          => 'Loi Darcos (frais de livraison des livres)',
            'product_types' => ['book'],
            'except_modes'  => $except,
            'rules'         => [
                ['max_amount' => round($law['threshold'] - 0.01, 2), 'type' => 'minimum', 'value' => $law['below'],
                 'comment'    => 'Livres : frais de livraison d’au moins ' . self::euros($law['below']) . ' TTC sous ' . self::euros($law['threshold']) . ' TTC de livres.'],
                ['min_amount' => $law['threshold'], 'type' => 'minimum', 'value' => $law['at_or_above'],
                 'comment'    => 'Livres : livraison jamais gratuite, au moins ' . self::euros($law['at_or_above']) . ' TTC.'],
            ],
        ]]);
    }

    private static function num($v): ?float
    {
        return is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
    }

    /** @return string[] */
    private static function strings($v): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($s) => trim((string) $s), (array) $v), static fn ($s) => $s !== '')));
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }
}
