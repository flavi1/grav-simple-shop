<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Tax\TaxResolver;
use Grav\Plugin\SimpleShop\Tax\TaxSettings;

/**
 * Raccourcis historiques vers le module de taxes (Tax\TaxSettings, Tax\TaxResolver) : base de saisie des prix, classe et
 * taux par défaut du site à l'adresse de la boutique. Le calcul d'un produit passe par Product\Catalog (taxe de sa classe).
 */
final class TaxManager
{
    private static function config(): array
    {
        return (array) Grav::instance()['config']->get('plugins.simple-shop', []);
    }

    /** @return array<string, array{label: string, rate: float}> Classes de taxe et leur taux à l'adresse de la boutique. */
    public static function rates(?array $config = null): array
    {
        $settings = TaxSettings::fromConfig($config ?? self::config());
        $out = [];
        foreach ($settings['classes'] as $code => $label) {
            $out[$code] = ['label' => $label, 'rate' => TaxResolver::forClass($code, null, $config ?? self::config())->toDecimal()];
        }

        return $out;
    }

    /** Classe de taxe par défaut du site. */
    public static function defaultCode(?array $config = null): string
    {
        return TaxSettings::fromConfig($config ?? self::config())['default_class'];
    }

    /** Taux de la classe par défaut à l'adresse de la boutique (ex. 0.2). */
    public static function defaultRate(?array $config = null): float
    {
        $config ??= self::config();

        return TaxResolver::forClass(self::defaultCode($config), null, $config)->toDecimal();
    }

    /** Vrai si les prix des produits sont saisis hors taxe (défaut). */
    public static function pricesEnteredHt(?array $config = null): bool
    {
        return TaxSettings::fromConfig($config ?? self::config())['prices_entered'] !== 'ttc';
    }

    /** Ancienne aide (Twig `shop_price_ttc`) : TTC au taux par défaut d'un montant saisi. Préférer Catalog::price(). */
    public static function toTtc(float $entered, ?array $config = null): float
    {
        $config ??= self::config();

        return self::pricesEnteredHt($config)
            ? self::round2($entered * (1 + self::defaultRate($config)))
            : self::round2($entered);
    }

    /** Prix HT (au centime) correspondant à un montant TTC, au taux par défaut. */
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
