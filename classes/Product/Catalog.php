<?php

namespace Grav\Plugin\SimpleShop\Product;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Pricing\Price;
use Grav\Plugin\SimpleShop\Pricing\TaxRate;
use Grav\Plugin\SimpleShop\Services\TaxManager;

/**
 * Point d'entrée du cœur pour interroger un produit (page) à travers son type : prix, stock, expédition, présentation.
 * Le cœur n'appelle jamais directement les champs d'une fiche.
 */
final class Catalog
{
    /** Devise du site (`plugins.simple-shop.currency`, défaut EUR). */
    public static function currency(?array $config = null): string
    {
        $config ??= self::config();
        $c = strtoupper(trim((string) ($config['currency'] ?? 'EUR')));

        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'EUR';
    }

    /** Taux de taxe d'un code (défaut : taxe du site). */
    public static function taxRate(?string $code = null, ?array $config = null): TaxRate
    {
        $config ??= self::config();
        $rates = TaxManager::rates($config);
        $code = $code !== null && isset($rates[$code]) ? $code : TaxManager::defaultCode($config);

        return TaxRate::fromDecimal($code, (float) $rates[$code]['rate']);
    }

    public static function context(ProductType $type, ?array $config = null, int $quantity = 1): PriceContext
    {
        $config ??= self::config();

        return new PriceContext(
            self::currency($config),
            self::taxRate($type->taxCode, $config),
            TaxManager::pricesEnteredHt($config) ? Price::HT : Price::TTC,
            new \DateTimeImmutable('today'),
            $quantity,
            $config
        );
    }

    /** Page produit publiée d'une route, avec son type ; null si la route n'est pas un produit. @return array{0: object, 1: ProductType}|null */
    public static function resolve(string $route): ?array
    {
        $pages = Grav::instance()['pages'];
        $pages->init();
        $page = $pages->dispatch($route);
        if (!$page || !$page->published()) {
            return null;
        }
        $type = ProductTypes::forPage($page);

        return $type ? [$page, $type] : null;
    }

    /** Prix unitaire d'un produit (null = pas vendable maintenant). */
    public static function price(string $route, array $options = []): ?Price
    {
        $r = self::resolve($route);
        if ($r === null) {
            return null;
        }
        [$page, $type] = $r;
        try {
            return $type->price($page, $options, self::context($type));
        } catch (\Throwable $e) {
            Grav::instance()['log']->error("[SimpleShop] Prix de {$route} incalculable : " . $e->getMessage());

            return null;
        }
    }

    private static function config(): array
    {
        return (array) Grav::instance()['config']->get('plugins.simple-shop', []);
    }
}
