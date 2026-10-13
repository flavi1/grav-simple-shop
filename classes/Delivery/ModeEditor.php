<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Nettoyage et fusion des modes de livraison reçus de l'interface d'administration (JSON) avant écriture dans
 * carriers.yaml. Sans dépendance à Grav : testable en ligne de commande.
 */
final class ModeEditor
{
    private const MAX_RULES = 200;

    /**
     * Valide et normalise un mode reçu de l'interface.
     *
     * @param array $in Champs bruts : name, description, enabled, legal_floor, margin, free_from, constraints, rules.
     *
     * @return array{0: array, 1: string[]} [mode normalisé, erreurs en français]
     */
    public static function sanitize(array $in, bool $isProviderMode): array
    {
        $errors = [];

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Le nom du mode est obligatoire.';
        }
        if (mb_strlen($name) > 80) {
            $errors[] = 'Le nom ne peut pas dépasser 80 caractères.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) > 300) {
            $errors[] = 'La description ne peut pas dépasser 300 caractères.';
        }

        $number = static function ($v, string $label, float $max = 100000.0) use (&$errors): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            if (is_string($v)) {
                $v = str_replace(',', '.', trim($v));
            }
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) {
                $errors[] = "{$label} : nombre positif attendu (au plus " . $max . ').';

                return null;
            }

            return round((float) $v, 4);
        };

        $margin = (array) ($in['margin'] ?? []);
        $constraints = (array) ($in['constraints'] ?? []);
        $maxWeight = $number($constraints['max_weight'] ?? null, 'Poids maximal', 1000000);
        $maxThickness = $number($constraints['max_thickness'] ?? null, 'Épaisseur maximale', 100000);

        $rules = [];
        $list = array_values(array_filter((array) ($in['rules'] ?? []), 'is_array'));
        if (count($list) > self::MAX_RULES) {
            $errors[] = 'Trop de lignes de tarif (maximum ' . self::MAX_RULES . ').';
            $list = array_slice($list, 0, self::MAX_RULES);
        }
        foreach ($list as $i => $r) {
            $n = $i + 1;
            $zone = (string) ($r['zone'] ?? 'FR-METRO');
            if (!in_array($zone, CarrierConfig::ZONES, true)) {
                $errors[] = "Ligne {$n} : zone inconnue.";
            }
            $price = $number($r['price'] ?? null, "Ligne {$n} : prix");
            if ($price === null) {
                $errors[] = "Ligne {$n} : le prix est obligatoire.";
            }
            $minW = $number($r['min_weight'] ?? 0, "Ligne {$n} : poids minimal") ?? 0.0;
            $maxW = $number($r['max_weight'] ?? null, "Ligne {$n} : poids maximal", 10000000);
            $minB = $number($r['min_basket'] ?? 0, "Ligne {$n} : panier minimal") ?? 0.0;
            $maxB = $number($r['max_basket'] ?? null, "Ligne {$n} : panier maximal");
            $thick = $number($r['max_thickness'] ?? null, "Ligne {$n} : épaisseur maximale", 100000);
            if ($maxW !== null && $minW > $maxW) {
                $errors[] = "Ligne {$n} : le poids minimal dépasse le poids maximal.";
            }
            if ($maxB !== null && $minB > $maxB) {
                $errors[] = "Ligne {$n} : le panier minimal dépasse le panier maximal.";
            }

            $rule = [
                'zone'        => $zone,
                'min_basket'  => $minB,
                'max_basket'  => $maxB ?? 99999.0,
                'min_weight'  => (int) $minW,
                'max_weight'  => (int) ($maxW ?? 9999999),
                'price'       => $price ?? 0.0,
            ];
            if ($thick !== null && $thick > 0) {
                $rule['max_thickness'] = (int) $thick;
            }
            $rules[] = $rule;
        }

        $clean = [
            'name'        => $name,
            'description' => $description,
            'enabled'     => filter_var($in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'legal_floor' => filter_var($in['legal_floor'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'margin'      => [
                'fixed'   => $number($margin['fixed'] ?? 0, 'Marge fixe', 1000) ?? 0.0,
                'percent' => $number($margin['percent'] ?? 0, 'Marge en %', 1000) ?? 0.0,
            ],
            'free_from'   => $number($in['free_from'] ?? null, 'Seuil « offert dès »'),
            'constraints' => [
                'max_weight'    => $maxWeight !== null && $maxWeight > 0 ? (int) $maxWeight : null,
                'max_thickness' => $maxThickness !== null && $maxThickness > 0 ? (int) $maxThickness : null,
            ],
            'rules'       => $rules,
        ];
        if ($clean['free_from'] !== null && $clean['free_from'] <= 0) {
            $clean['free_from'] = null;
        }

        return [$clean, $errors];
    }

    /**
     * Entrée à écrire dans carriers.yaml.
     * Mode manuel : tous les champs. Mode de fournisseur : seulement ceux qui diffèrent des valeurs du fournisseur.
     *
     * @param array      $clean    Sortie de sanitize().
     * @param array|null $defaults Valeurs du fournisseur (`provider_defaults` du catalogue), null pour un mode manuel.
     */
    public static function toStorage(array $clean, ?array $defaults): array
    {
        if ($defaults === null) {
            $out = $clean;
            if ($out['free_from'] === null) {
                unset($out['free_from']);
            }
            if ($out['constraints']['max_weight'] === null && $out['constraints']['max_thickness'] === null) {
                unset($out['constraints']);
            }

            return $out;
        }

        [$cleanDefaults] = self::sanitize($defaults, true);
        $out = [];
        foreach (CarrierCatalog::EDITABLE as $field) {
            if ($clean[$field] != $cleanDefaults[$field]) {
                $out[$field] = $clean[$field];
            }
        }

        return $out;
    }

    /** Une clé de mode manuel est-elle valide ? (minuscules, chiffres, _ ; 2 à 40 caractères) */
    public static function isManualKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_]{2,40}$/', $key) === 1;
    }

    /** Clé proposée à partir d'un nom (« Retrait à l'atelier » → retrait_a_l_atelier). */
    public static function slug(string $name): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)), '_'));

        return substr($s !== '' ? $s : 'mode', 0, 40);
    }
}
