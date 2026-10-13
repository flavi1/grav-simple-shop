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

        foreach ($data['carriers'] as $key => $carrier) {
            $where = "Transporteur « {$key} »";

            if (!is_array($carrier)) {
                $errors[] = "{$where} : doit être un bloc (name, enabled, rules…).";
                continue;
            }
            if (trim((string) ($carrier['name'] ?? '')) === '') {
                $errors[] = "{$where} : le nom (name) est obligatoire.";
            }
            if (isset($carrier['enabled']) && !is_bool($carrier['enabled']) && !in_array($carrier['enabled'], [0, 1], true)) {
                $errors[] = "{$where} : enabled doit valoir true ou false.";
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
