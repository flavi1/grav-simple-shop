<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Assemble la liste complète des modes de livraison :
 *   - modes des fournisseurs (plugins transporteur), avec leurs valeurs par défaut ;
 *   - réglages enregistrés dans `carriers.yaml` : modes MANUELS (sans fournisseur) et SURCHARGES des modes
 *     d'un fournisseur (clé `<fournisseur>.<mode>`), champ par champ.
 *
 * Classe sans dépendance à Grav : testable en ligne de commande.
 */
final class CarrierCatalog
{
    /** Champs d'un mode que l'administration peut modifier (le reste vient du fournisseur). */
    public const EDITABLE = ['name', 'description', 'enabled', 'legal_floor', 'margin', 'free_from', 'constraints', 'rules'];

    /** Champs qui n'appartiennent qu'au fournisseur. */
    public const PROVIDER_ONLY = ['requires_pickup_point', 'available', 'unavailable_reason'];

    /**
     * @param CarrierProviderInterface[] $providers Fournisseurs enregistrés (id => objet).
     * @param array<string, array>       $saved     Contenu de `carriers:` dans carriers.yaml.
     *
     * @return array<string, array> Modes par clé complète, chacun avec :
     *         provider (?string), local_key, source ('manual'|'provider'|'provider+override'),
     *         requires_pickup_point (bool), available (bool), et les champs du format carriers.yaml.
     */
    public static function build(array $providers, array $saved): array
    {
        $modes = [];

        foreach ($providers as $id => $provider) {
            if (!$provider instanceof CarrierProviderInterface || $provider->getId() !== (string) $id) {
                continue;
            }
            if ($provider::API_VERSION !== CarrierProviderInterface::API_VERSION) {
                continue;
            }
            foreach ($provider->getModes() as $local => $def) {
                $key = $provider->getId() . '.' . $local;
                $override = is_array($saved[$key] ?? null) ? $saved[$key] : null;
                $mode = $def;
                if ($override !== null) {
                    foreach (self::EDITABLE as $field) {
                        if (array_key_exists($field, $override)) {
                            $mode[$field] = $override[$field];
                        }
                    }
                }
                $modes[$key] = self::normalize($mode, $key, $provider->getId(), $local, $override !== null ? 'provider+override' : 'provider');
                $modes[$key]['provider_defaults'] = self::normalize($def, $key, $provider->getId(), $local, 'provider');
            }
        }

        foreach ($saved as $key => $def) {
            $key = (string) $key;
            if (!is_array($def) || isset($modes[$key]) || strpos($key, '.') !== false) {
                // Les entrées « fournisseur.mode » sans fournisseur actif sont ignorées (plugin désactivé).
                continue;
            }
            $modes[$key] = self::normalize($def, $key, null, $key, 'manual');
        }

        return $modes;
    }

    private static function normalize(array $mode, string $key, ?string $provider, string $local, string $source): array
    {
        $constraints = (array) ($mode['constraints'] ?? []);
        $margin = (array) ($mode['margin'] ?? []);

        return [
            'key'                   => $key,
            'provider'              => $provider,
            'local_key'             => $local,
            'source'                => $source,
            'name'                  => (string) ($mode['name'] ?? $key),
            'description'           => (string) ($mode['description'] ?? ''),
            'enabled'               => (bool) ($mode['enabled'] ?? false),
            'legal_floor'           => ($mode['legal_floor'] ?? true) !== false,
            'margin'                => [
                'fixed'   => max(0.0, (float) ($margin['fixed'] ?? 0)),
                'percent' => max(0.0, (float) ($margin['percent'] ?? 0)),
            ],
            'free_from'             => isset($mode['free_from']) && is_numeric($mode['free_from']) && $mode['free_from'] > 0 ? (float) $mode['free_from'] : null,
            'constraints'           => [
                'max_weight'    => !empty($constraints['max_weight']) ? (int) $constraints['max_weight'] : null,
                'max_thickness' => !empty($constraints['max_thickness']) ? (int) $constraints['max_thickness'] : null,
            ],
            'rules'                 => array_values(array_filter((array) ($mode['rules'] ?? []), 'is_array')),
            'requires_pickup_point' => $provider !== null && !empty($mode['requires_pickup_point']),
            'available'             => $provider === null ? true : ($mode['available'] ?? true) !== false,
            'unavailable_reason'    => (string) ($mode['unavailable_reason'] ?? ''),
        ];
    }

    /**
     * Fabrique l'entrée à écrire dans carriers.yaml pour un mode modifié dans l'administration.
     * Pour un mode de fournisseur, seuls les champs différents des valeurs par défaut sont conservés.
     *
     * @param array $edited  Champs reçus (name, description, enabled, legal_floor, margin, free_from, constraints, rules).
     * @param array|null $defaults Valeurs par défaut normalisées du fournisseur (`provider_defaults`), ou null pour un mode manuel.
     */
    public static function toStorage(array $edited, ?array $defaults): array
    {
        $out = [];
        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $edited)) {
                continue;
            }
            if ($defaults !== null && ($defaults[$field] ?? null) == $edited[$field]) {
                continue;
            }
            $out[$field] = $edited[$field];
        }

        return $out;
    }
}
