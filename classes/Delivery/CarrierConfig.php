<?php

namespace Grav\Plugin\SimpleShop\Delivery;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lecture, validation et écriture du fichier des transporteurs
 * (`user://config/plugins/simple-shop/carriers.yaml`), édité depuis l'espace
 * d'administration.
 *
 * Le texte YAML saisi est conservé tel quel (commentaires inclus) une fois
 * validé. Format attendu : voir `config/plugins/simple-shop/carriers.dist.yaml`.
 *
 * Livraison : France métropolitaine hors Corse uniquement (zone `FR-METRO`),
 * les autres zones restent acceptées par le validateur pour une éventuelle
 * ouverture future mais ne sont jamais atteintes par ZoneResolver tant que
 * l'adresse est limitée à la France métropolitaine.
 */
class CarrierConfig
{
    /** Zones géographiques reconnues (voir ZoneResolver::resolve()). */
    public const ZONES = ['FR-METRO', 'FR-CORSE', 'FR-OM', 'EU-ZONE-1', 'INTERNATIONAL'];

    /** Champs numériques d'un palier de tarif. */
    private const RULE_NUMBERS = ['min_basket', 'max_basket', 'min_weight', 'max_weight', 'max_thickness', 'price'];

    /**
     * Valide un texte YAML de configuration des transporteurs.
     *
     * @return string[] Liste des erreurs (vide si le texte est valide).
     */
    public static function validate(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return ['YAML invalide : ' . $e->getMessage()];
        }

        if (!is_array($data) || !isset($data['carriers']) || !is_array($data['carriers'])) {
            return ["Le fichier doit contenir une clé « carriers » (liste de transporteurs)."];
        }

        $errors = [];
        foreach (ShippingConstraints::validate($data['constraints'] ?? null) as $e) {
            $errors[] = 'Contraintes globales : ' . $e;
        }

        foreach ($data['carriers'] as $key => $carrier) {
            $where = "Transporteur « {$key} »";

            if (!is_array($carrier)) {
                $errors[] = "{$where} : doit être un bloc (name, enabled, rules…).";
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', (string) $key)) {
                $errors[] = "{$where} : la clé ne peut contenir que des minuscules, chiffres et _ (et un point pour un mode de fournisseur).";
            }
            // Un mode de fournisseur (clé « fournisseur.mode ») peut ne surcharger que quelques champs.
            if (strpos((string) $key, '.') === false && trim((string) ($carrier['name'] ?? '')) === '') {
                $errors[] = "{$where} : le nom (name) est obligatoire.";
            }
            if (isset($carrier['free_from']) && (!is_numeric($carrier['free_from']) || $carrier['free_from'] < 0)) {
                $errors[] = "{$where} : free_from doit être un nombre positif ou nul.";
            }
            if (isset($carrier['pricing']) && !in_array($carrier['pricing'], CarrierCatalog::PRICING, true)) {
                $errors[] = "{$where} : pricing doit valoir flat (forfait), provider (grille du plugin) ou grid (grille personnalisée).";
            }
            if (($carrier['pricing'] ?? null) === 'provider' && strpos((string) $key, '.') === false) {
                $errors[] = "{$where} : pricing « provider » est réservé aux modes d'un plugin transporteur.";
            }
            if (isset($carrier['flat_price']) && (!is_numeric($carrier['flat_price']) || $carrier['flat_price'] < 0)) {
                $errors[] = "{$where} : flat_price doit être un nombre positif ou nul.";
            }
            if (($carrier['pricing'] ?? null) === 'flat' && strpos((string) $key, '.') === false && !isset($carrier['flat_price'])) {
                $errors[] = "{$where} : un forfait (pricing: flat) demande un prix (flat_price).";
            }
            if (isset($carrier['kind']) && !in_array($carrier['kind'], ['delivery', 'local_pickup'], true)) {
                $errors[] = "{$where} : kind doit valoir delivery ou local_pickup (retrait sur place).";
            }
            if (($carrier['kind'] ?? '') === 'local_pickup') {
                if (strpos((string) $key, '.') !== false) {
                    $errors[] = "{$where} : le retrait sur place est un mode manuel (clé sans point).";
                }
                if (isset($carrier['max_distance_km']) && (!is_numeric($carrier['max_distance_km']) || $carrier['max_distance_km'] < 0)) {
                    $errors[] = "{$where} : max_distance_km doit être un nombre positif.";
                }
                if (isset($carrier['pickup_address']) && !is_array($carrier['pickup_address'])) {
                    $errors[] = "{$where} : pickup_address doit être un bloc (street, postal_code, city).";
                }
            }
            if (isset($carrier['enabled']) && !is_bool($carrier['enabled']) && !in_array($carrier['enabled'], [0, 1], true)) {
                $errors[] = "{$where} : enabled doit valoir true ou false.";
            }

            foreach (['fixed', 'percent'] as $m) {
                $v = $carrier['margin'][$m] ?? null;
                if ($v !== null && (!is_numeric($v) || $v < 0)) {
                    $errors[] = "{$where} : margin.{$m} doit être un nombre positif ou nul.";
                }
            }
            if (isset($carrier['margin']) && !is_array($carrier['margin'])) {
                $errors[] = "{$where} : margin doit être un bloc (fixed, percent).";
            }

            foreach (['max_weight', 'max_thickness'] as $limit) {
                $v = $carrier['constraints'][$limit] ?? null;
                if ($v !== null && !is_numeric($v)) {
                    $errors[] = "{$where} : constraints.{$limit} doit être un nombre ou null.";
                }
            }

            $rules = $carrier['rules'] ?? [];
            if (!is_array($rules)) {
                $errors[] = "{$where} : rules doit être une liste de paliers.";
                continue;
            }

            foreach (array_values($rules) as $i => $rule) {
                $ruleWhere = "{$where}, palier " . ($i + 1);

                if (!is_array($rule)) {
                    $errors[] = "{$ruleWhere} : doit être un bloc (zone, price…).";
                    continue;
                }
                if (!in_array($rule['zone'] ?? null, self::ZONES, true)) {
                    $errors[] = "{$ruleWhere} : zone inconnue (attendu : " . implode(', ', self::ZONES) . ').';
                }
                if (!isset($rule['price'])) {
                    $errors[] = "{$ruleWhere} : le prix (price) est obligatoire.";
                }
                foreach (self::RULE_NUMBERS as $field) {
                    if (isset($rule[$field]) && (!is_numeric($rule[$field]) || $rule[$field] < 0)) {
                        $errors[] = "{$ruleWhere} : {$field} doit être un nombre positif ou nul.";
                    }
                }
                if (isset($rule['min_weight'], $rule['max_weight']) && is_numeric($rule['min_weight']) && is_numeric($rule['max_weight'])
                    && $rule['min_weight'] > $rule['max_weight']) {
                    $errors[] = "{$ruleWhere} : min_weight dépasse max_weight.";
                }
                if (isset($rule['min_basket'], $rule['max_basket']) && is_numeric($rule['min_basket']) && is_numeric($rule['max_basket'])
                    && $rule['min_basket'] > $rule['max_basket']) {
                    $errors[] = "{$ruleWhere} : min_basket dépasse max_basket.";
                }
            }
        }

        return $errors;
    }

    /**
     * Texte YAML d'un tableau de modes (écriture depuis l'interface d'administration ; les commentaires d'un fichier
     * édité à la main ne sont pas conservés).
     *
     * @param array<string, array> $carriers
     * @param array|null           $constraints Bloc `constraints` (ShippingConstraints::toStorage) ; null = absent.
     */
    public static function dump(array $carriers, ?array $constraints = null): string
    {
        $header = "# Modes de livraison — écrit par l'administration (Boutique > Transporteurs).\n"
            . "# Prix hors taxe par défaut (plugins.simple-shop.delivery.prices_entered). Les modes d'un plugin transporteur\n"
            . "# (clé « fournisseur.mode ») ne contiennent que les valeurs modifiées par rapport aux réglages du plugin.\n\n";

        $data = $constraints !== null ? ['constraints' => $constraints, 'carriers' => $carriers] : ['carriers' => $carriers];

        return $header . Yaml::dump($data, 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    /**
     * Écrit le fichier après validation : sauvegarde `.bak` de l'ancienne
     * version, écriture via fichier temporaire puis renommage (jamais de
     * fichier tronqué).
     *
     * @return string[] Erreurs de validation (vide = écrit).
     *
     * @throws \RuntimeException Si l'écriture échoue.
     */
    public static function save(string $path, string $yaml): array
    {
        $errors = self::validate($yaml);
        if ($errors !== []) {
            return $errors;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier inaccessible : {$dir}");
        }

        if (is_file($path)) {
            @copy($path, $path . '.bak');
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $yaml) === false) {
            throw new \RuntimeException("Écriture impossible : {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Remplacement impossible : {$path}");
        }

        return [];
    }
}
