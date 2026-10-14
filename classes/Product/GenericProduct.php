<?php

namespace Grav\Plugin\SimpleShop\Product;

use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Pricing\Price;

/**
 * Comportement générique d'un produit (défaut de chaque clé `shop.*` non renseignée dans le blueprint) :
 *
 *  - prix : champ `product_price`, saisi HT ou TTC (`price_entered_as` de la fiche, sinon réglage du site) ;
 *    une fiche dont `sales_method` est renseigné et différent de `sell_here` n'est pas vendable ici ;
 *  - stock : champ `product_quantity` (-1 = illimité, absent = 0) ;
 *  - expédition : `weight` (g) et `thickness` (mm) ;
 *  - présentation : titre de la page, sans image ;
 *  - options : aucune acceptée.
 *
 * Rien ici n'est propre à un métier (livres, formations…) : un type de produit spécialise ce qui doit l'être.
 */
final class GenericProduct
{
    public static function price($page, array $options, PriceContext $ctx): ?Price
    {
        $h = (array) $page->header();
        $sales = $h['sales_method'] ?? 'sell_here';
        if ($sales !== '' && $sales !== 'sell_here') {
            return null;
        }

        return self::priceFromField($h, 'product_price', $ctx);
    }

    /**
     * Prix lu dans un champ de la fiche. Utilisable par les types spécialisés (ex. prix de prévente).
     * Une ancienne valeur HT à plus de 2 décimales (ex. 12.2749) garde exactement son prix TTC d'origine.
     */
    public static function priceFromField(array $header, string $field, PriceContext $ctx): ?Price
    {
        $raw = $header[$field] ?? null;
        if ($raw === null || $raw === '' || !is_numeric(str_replace(',', '.', (string) $raw))) {
            return null;
        }
        $raw = str_replace(',', '.', trim((string) $raw));
        $enteredAs = in_array($header['price_entered_as'] ?? null, [Price::HT, Price::TTC], true) ? $header['price_entered_as'] : $ctx->defaultEnteredAs;

        if (!preg_match('/' . Money::INPUT_PATTERN . '/', $raw)) {
            // Ancienne saisie (plus de 2 décimales) : on reproduit l'ancien calcul (TTC arrondi au centime) et on le prend comme référence.
            $ttc = $enteredAs === Price::HT
                ? round((float) $raw * (1 + $ctx->tax->toDecimal()) + 1e-9, 2)
                : round((float) $raw + 1e-9, 2);

            return Price::fromEntered(Money::fromLegacyDecimal($ttc, $ctx->currency), $ctx->tax, Price::TTC);
        }

        return Price::fromEntered(Money::parse($raw, $ctx->currency), $ctx->tax, $enteredAs);
    }

    public static function stock($page, array $options): ?int
    {
        $h = (array) $page->header();
        $q = isset($h['product_quantity']) && is_numeric($h['product_quantity']) ? (int) $h['product_quantity'] : 0;

        return $q === -1 ? null : max(0, $q);
    }

    public static function shipping($page, array $options): ?array
    {
        $h = (array) $page->header();

        return [
            'weight_g'     => isset($h['weight']) && is_numeric($h['weight']) ? (int) round((float) $h['weight']) : 0,
            'thickness_mm' => isset($h['thickness']) && is_numeric($h['thickness']) ? (int) round((float) $h['thickness']) : 0,
        ];
    }

    public static function presentation($page, array $options): array
    {
        return ['label' => (string) $page->title(), 'image' => null];
    }

    public static function options($page, array $raw): array
    {
        if ($raw !== []) {
            throw new \InvalidArgumentException('Ce produit n’accepte pas d’options.');
        }

        return [];
    }
}
