<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;

/**
 * Taxes de la boutique (TVA).
 *
 * Les PRIX DES PRODUITS sont saisis HORS TAXE (`plugins.simple-shop.taxes.prices_entered: ht`) ;
 * tout le reste du tunnel (panier, livraison, paiement, commande, facture)
 * travaille en TTC, comme avant : la conversion se fait à un seul endroit, ici,
 * à la lecture du prix du produit ({@see \Grav\Plugin\SimpleShop\Inventory\InventoryManager}).
 *
 * Les types de taxes (code => libellé + taux) se gèrent dans l'administration :
 *
 *     taxes:
 *       prices_entered: ht        # ht | ttc
 *       default: TVA_5_5          # code appliqué à tout le site
 *       rates:
 *         TVA_5_5: { label: 'TVA 5,5 %', rate: 0.055 }
 *         TVA_20:  { label: 'TVA 20 %',  rate: 0.20 }
 *
 * Limite assumée : un seul code pour tout le site (produits ET livraison). Un
 * code par produit ou par ligne de frais de port demanderait une facture
 * multi-taux (voir CONCEPTION-TRANSPORTEURS.md).
 *
 * Arrondi : le prix unitaire TTC est arrondi au centime (demi supérieur) ; la
 * ligne vaut prix unitaire TTC × quantité. La TVA d'une facture est le total
 * TTC moins le total HT.
 */
final class TaxManager
{
    public const DEFAULT_CODE = 'TVA_5_5';

    /** Types de taxes disponibles quand la configuration n'en définit pas. */
    private const DEFAULT_RATES = [
        'TVA_5_5' => ['label' => 'TVA 5,5 %', 'rate' => 0.055],
        'TVA_20'  => ['label' => 'TVA 20 %',  'rate' => 0.20],
    ];

    private static function config(): array
    {
        return (array) Grav::instance()['config']->get('plugins.simple-shop', []);
    }

    /**
     * Types de taxes disponibles : code => ['label' => string, 'rate' => float].
     *
     * Accepte `rates` sous forme de table (code => définition) ou de liste
     * d'éléments portant leur `code` (format d'un champ « liste » d'Admin).
     *
     * @return array<string, array{label: string, rate: float}>
     */
    public static function rates(?array $config = null): array
    {
        $config ??= self::config();
        $raw = $config['taxes']['rates'] ?? null;
        $rates = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $code = (string) ($def['code'] ?? (is_string($key) ? $key : ''));
                if ($code === '' || !isset($def['rate']) || !is_numeric($def['rate'])) {
                    continue;
                }
                $rate = (float) $def['rate'];
                // Un taux saisi en pourcentage (5.5) est converti (0.055).
                if ($rate > 1) {
                    $rate /= 100;
                }
                if ($rate < 0 || $rate >= 1) {
                    continue;
                }
                $rates[$code] = ['label' => (string) ($def['label'] ?? $code), 'rate' => $rate];
            }
        }

        return $rates !== [] ? $rates : self::DEFAULT_RATES;
    }

    /** Code de la taxe appliquée à tout le site. */
    public static function defaultCode(?array $config = null): string
    {
        $config ??= self::config();
        $code = (string) ($config['taxes']['default'] ?? self::DEFAULT_CODE);

        return isset(self::rates($config)[$code]) ? $code : (string) array_key_first(self::rates($config));
    }

    /**
     * Taux appliqué à tout le site (ex. 0.055).
     *
     * Repli historique : l'ancienne clé `vat_rate`, si aucune taxe n'est configurée.
     */
    public static function defaultRate(?array $config = null): float
    {
        $config ??= self::config();

        if (!isset($config['taxes']['rates']) && isset($config['vat_rate']) && is_numeric($config['vat_rate'])) {
            return (float) $config['vat_rate'];
        }

        return self::rates($config)[self::defaultCode($config)]['rate'];
    }

    /** Vrai si les prix des produits sont saisis hors taxe (défaut). */
    public static function pricesEnteredHt(?array $config = null): bool
    {
        $config ??= self::config();

        return strtolower((string) ($config['taxes']['prices_entered'] ?? 'ht')) !== 'ttc';
    }

    /** Prix TTC (au centime) d'un montant saisi dans la fiche produit. */
    public static function toTtc(float $entered, ?array $config = null): float
    {
        $config ??= self::config();

        return self::pricesEnteredHt($config)
            ? self::round2($entered * (1 + self::defaultRate($config)))
            : self::round2($entered);
    }

    /** Prix HT (au centime) correspondant à un montant TTC. */
    public static function toHt(float $ttc, ?array $config = null): float
    {
        return self::round2($ttc / (1 + self::defaultRate($config)));
    }

    /** Arrondi au centime, demi supérieur (insensible aux erreurs binaires). */
    public static function round2(float $amount): float
    {
        return round($amount + 1e-9, 2);
    }
}
