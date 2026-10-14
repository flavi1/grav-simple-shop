<?php

namespace Grav\Plugin\SimpleShop\Product;

use Grav\Plugin\SimpleShop\Pricing\Price;

/**
 * Type de produit : un blueprint de page qui déclare `shop.price_calculation` (et d'autres clés `shop.*` facultatives),
 * chacune désignant une méthode statique « Classe::méthode ». Une clé absente prend le comportement de GenericProduct.
 *
 *     shop:
 *       price_calculation: 'Grav\Theme\Bookstore::bookPrice'   # obligatoire (sauf type « générique » de repli)
 *       tax: TVA_5_5                                            # code de taxe du type (défaut : taxe du site)
 *       stock: …                                                # facultatif
 *       shipping: …                                             # facultatif ; une réponse null = produit non expédié
 *       presentation: …                                         # facultatif : ['label' => …, 'image' => …]
 *       options: …                                              # facultatif : options acceptées dans le panier
 *       price_fields: [product_price, …]                        # champs de la fiche qui contiennent un prix saisi
 *                                                               # (défaut : product_price) — convertis si la base HT/TTC du site change
 *
 * Contrat des réponses (vérifié ici) : price → Price|null ; stock → int|null (null = illimité) ;
 * shipping → array{weight_g:int, thickness_mm:int}|null ; presentation → array{label:string, image:?string} ; options → array.
 */
final class ProductType
{
    public const API_VERSION = 1;
    public const HANDLERS = ['price_calculation', 'stock', 'shipping', 'presentation', 'options'];
    private const DEFAULTS = [
        'price_calculation' => [GenericProduct::class, 'price'],
        'stock'             => [GenericProduct::class, 'stock'],
        'shipping'          => [GenericProduct::class, 'shipping'],
        'presentation'      => [GenericProduct::class, 'presentation'],
        'options'           => [GenericProduct::class, 'options'],
    ];

    /**
     * @param array<string, string> $handlers    clé => « Classe::méthode » (déjà vérifiés).
     * @param string[]              $priceFields Champs de la fiche contenant un prix saisi (HT ou TTC).
     */
    public function __construct(
        public readonly string $name,
        private array $handlers = [],
        public readonly ?string $taxCode = null,
        public readonly array $priceFields = ['product_price']
    ) {
    }

    public function price($page, array $options, PriceContext $ctx): ?Price
    {
        $r = $this->call('price_calculation', [$page, $options, $ctx]);
        if ($r !== null && !$r instanceof Price) {
            throw new \UnexpectedValueException("Type « {$this->name} » : price_calculation doit retourner un Price ou null.");
        }
        if ($r !== null && $r->currency() !== $ctx->currency) {
            throw new \UnexpectedValueException("Type « {$this->name} » : devise {$r->currency()} différente de celle du site ({$ctx->currency}).");
        }

        return $r;
    }

    public function stock($page, array $options): ?int
    {
        $r = $this->call('stock', [$page, $options]);

        return $r === null ? null : max(0, (int) $r);
    }

    public function shipping($page, array $options): ?array
    {
        $r = $this->call('shipping', [$page, $options]);
        if ($r === null) {
            return null;
        }

        return ['weight_g' => max(0, (int) ($r['weight_g'] ?? 0)), 'thickness_mm' => max(0, (int) ($r['thickness_mm'] ?? 0))];
    }

    public function presentation($page, array $options): array
    {
        $r = (array) $this->call('presentation', [$page, $options]);

        return ['label' => (string) ($r['label'] ?? $page->title()), 'image' => isset($r['image']) && $r['image'] !== '' ? (string) $r['image'] : null];
    }

    public function options($page, array $raw): array
    {
        return (array) $this->call('options', [$page, $raw]);
    }

    private function call(string $key, array $args)
    {
        $callable = isset($this->handlers[$key]) ? explode('::', $this->handlers[$key], 2) : self::DEFAULTS[$key];

        return \call_user_func_array($callable, $args);
    }
}
