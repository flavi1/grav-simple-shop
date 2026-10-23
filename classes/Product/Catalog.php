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

    /**
     * Taxe d'un type de taxe (ou d'un ancien code « TVA_5_5 ») à l'adresse qui décide du taux (voir Tax\TaxResolver) ;
     * sans nom : le type de taxe par défaut du site.
     */
    public static function taxRate(?string $code = null, ?array $config = null): TaxRate
    {
        $key = $code ?? \Grav\Plugin\SimpleShop\Tax\TaxResolver::settings($config)['default_rate'];

        return \Grav\Plugin\SimpleShop\Tax\TaxResolver::forRate($key, null, $config);
    }

    /**
     * Contexte de calcul du prix d'un produit. Taxe : type de taxe de la fiche (`tax_type`), sinon celui choisi pour le type
     * de produit dans l'administration, sinon celui du blueprint (`shop.tax`), sinon le type par défaut.
     */
    public static function context(ProductType $type, ?array $config = null, int $quantity = 1, $page = null): PriceContext
    {
        $explicit = $config !== null;
        $config ??= self::config();
        $header = $page ? (array) $page->header() : [];
        $rateKey = \Grav\Plugin\SimpleShop\Tax\TaxResolver::rateFor($type->name, $type->taxCode, $header, $explicit ? $config : null);

        return new PriceContext(
            self::currency($config),
            self::taxRate($rateKey, $explicit ? $config : null),
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
        if ($type === null || !ProductTypes::salesEnabled($type->name)) {
            return null;   // type hors vente (configuration « Ventes actives ») : ni prix, ni achat
        }

        return [$page, $type];
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
            return $type->price($page, $options, self::context($type, null, 1, $page));
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
