<?php

namespace Grav\Plugin\SimpleShop\Product;

use Grav\Plugin\SimpleShop\Pricing\TaxRate;

/**
 * Contexte d'un calcul de prix (`shop.price_calculation`) : date, quantité, devise du site, taxe du type de produit,
 * base de saisie par défaut, configuration du plugin. Immuable.
 */
final class PriceContext
{
    public function __construct(
        public readonly string $currency,
        public readonly TaxRate $tax,
        public readonly string $defaultEnteredAs,
        public readonly \DateTimeImmutable $date,
        public readonly int $quantity = 1,
        public readonly array $config = []
    ) {
    }
}
