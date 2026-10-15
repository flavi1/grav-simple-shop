<?php

namespace Grav\Plugin\SimpleShop\Orders;

use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Pricing\TaxComponent;
use Grav\Plugin\SimpleShop\Pricing\TaxRate;
use Grav\Plugin\SimpleShop\Tax\TaxResolver;

/**
 * Montants d'une commande, en centimes entiers : une seule source pour le panier, le paiement, la commande enregistrée
 * et la facture.
 *
 * Une **ligne** = produit (ou part des frais de livraison) avec ses prix unitaires HT et TTC, sa taxe (code de classe,
 * taux effectif en points de base, composantes) et sa quantité. Le **total payé** est la somme des TTC des lignes.
 *
 * Livraison : une ligne par taxe. Selon le réglage `taxes.shipping_tax` (Tax\TaxResolver::shippingParts()), une seule
 * (taux des articles, le plus élevé si le panier est mixte, ou classe fixe) ou plusieurs (prorata du HT des articles).
 *
 * Facture : les lignes sont regroupées par taxe élémentaire (catégorie + taux) : base HT = somme des HT,
 * TVA = arrondi(base × taux) ; l'écart avec le montant encaissé est le montant d'arrondi BT-114.
 *
 * Les commandes enregistrées avant les centimes sont relues depuis leurs montants décimaux et le taux de leur facture.
 */
final class OrderAmounts
{
    /**
     * Montants du panier en cours.
     *
     * @param array $cartItems      Lignes de CartStorage::getCartItems() (`unit_ttc_cents`, `unit_ht_cents`, `tax_code`, `tax_bp`, `tax_components`…).
     * @param array $shipping       Livraison choisie (`price_cents`, sinon ancien `price` décimal TTC ; `name`).
     * @param array $shippingParts  Parts de taxe de la livraison (Tax\TaxResolver::shippingParts()).
     */
    public static function fromCart(array $cartItems, array $shipping, array $shippingParts, string $currency): array
    {
        $lines = [];
        $fallback = $shippingParts[0]['rate'] ?? null;
        foreach ($cartItems as $key => $item) {
            $route = (string) ($item['route'] ?? $key);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $ttc = isset($item['unit_ttc_cents']) ? (int) $item['unit_ttc_cents'] : self::cents($item['price'] ?? 0);
            $bp = isset($item['tax_bp']) ? (int) $item['tax_bp'] : ($fallback?->basisPoints ?? 0);
            $ht = isset($item['unit_ht_cents']) ? (int) $item['unit_ht_cents'] : (new TaxRate('x', $bp))->removeFrom(Money::of($ttc, $currency))->amount;
            $components = (array) ($item['tax_components'] ?? []);
            $lines[] = self::line('item', (string) ($item['title'] ?? $route), $qty, $ht, $ttc, (string) ($item['tax_code'] ?? $fallback?->code ?? ''), $bp,
                $components ?: self::defaultComponents((string) ($item['tax_code'] ?? ''), $bp), $route, (string) ($item['line_id'] ?? $key), (array) ($item['options'] ?? []));
        }
        $ttc = isset($shipping['price_cents']) ? (int) $shipping['price_cents'] : self::cents($shipping['price'] ?? 0);
        $parts = TaxResolver::splitShippingTtc($ttc, $shippingParts, $currency);
        foreach ($parts as $p) {
            $lines[] = self::shippingLine($shipping, $p, count($parts) > 1);
        }

        return self::assemble($lines, $currency);
    }

    /** Montants du panier avec la devise du site et la taxe de livraison déduite des articles expédiés. */
    public static function forSite(array $cartItems, array $shipping): array
    {
        return self::fromCart($cartItems, $shipping, TaxResolver::shippingParts(self::taxMix($cartItems)), \Grav\Plugin\SimpleShop\Product\Catalog::currency());
    }

    /** Classe de taxe → HT (centimes) des articles expédiés du panier. */
    public static function taxMix(array $cartItems): array
    {
        $mix = [];
        foreach ($cartItems as $item) {
            if (($item['shippable'] ?? true) && !empty($item['tax_code'])) {
                $mix[$item['tax_code']] = ($mix[$item['tax_code']] ?? 0) + (int) ($item['unit_ht_cents'] ?? 0) * (int) ($item['quantity'] ?? 0);
            }
        }

        return $mix;
    }

    /** Montants d'une commande enregistrée, taxe du site et taux figé sur sa facture (anciennes commandes). */
    public static function forStoredOrder(array $order, array $items, ?float $invoiceRate = null): array
    {
        return self::fromStored($order, $items, \Grav\Plugin\SimpleShop\Product\Catalog::taxRate(), $invoiceRate);
    }

    /**
     * Montants d'une commande enregistrée (lignes `order` et `order_item`).
     *
     * @param float|null $legacyRate Taux décimal pour une ancienne commande (taux figé sur sa facture), sinon taux du site.
     */
    public static function fromStored(array $order, array $items, TaxRate $siteTax, ?float $legacyRate = null): array
    {
        $currency = (string) ($order['currency'] ?? '') !== '' ? (string) $order['currency'] : 'EUR';
        $legacyTax = $legacyRate !== null && $legacyRate > 0 ? TaxRate::fromDecimal($siteTax->code, $legacyRate) : $siteTax;

        $lines = [];
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            if (isset($item['unit_ttc_cents']) && $item['unit_ttc_cents'] !== null && $item['unit_ttc_cents'] !== '') {
                $ttc = (int) $item['unit_ttc_cents'];
                $ht = (int) $item['unit_ht_cents'];
                $code = (string) $item['tax_code'];
                $bp = (int) $item['tax_bp'];
            } else {
                $ttc = self::cents($item['price'] ?? 0);
                $ht = $legacyTax->removeFrom(Money::of($ttc, $currency))->amount;
                $code = $legacyTax->code;
                $bp = $legacyTax->basisPoints;
            }
            $components = json_decode((string) ($item['taxes'] ?? ''), true);
            $options = json_decode((string) ($item['options'] ?? '{}'), true);
            $lines[] = self::line('item', (string) ($item['title'] ?? ''), $qty, $ht, $ttc, $code, $bp,
                is_array($components) && $components !== [] ? $components : self::defaultComponents($code, $bp),
                (string) ($item['product_route'] ?? ''), (string) ($item['line_id'] ?? ''), is_array($options) ? $options : []);
        }

        $shipping = json_decode((string) ($order['shipping_data'] ?? '{}'), true) ?: [];
        $parts = json_decode((string) ($order['shipping_taxes'] ?? ''), true);
        if (is_array($parts) && $parts !== []) {
            foreach ($parts as $p) {
                $lines[] = self::line('shipping', (string) ($p['title'] ?? self::shippingTitle($shipping)), 1, (int) $p['ht'], (int) $p['ttc'],
                    (string) $p['code'], (int) $p['bp'], (array) ($p['taxes'] ?? []));
            }
        } elseif (isset($order['shipping_ttc_cents']) && $order['shipping_ttc_cents'] !== null && $order['shipping_ttc_cents'] !== '') {
            if ((int) $order['shipping_ttc_cents'] > 0) {
                $lines[] = self::line('shipping', self::shippingTitle($shipping), 1, (int) $order['shipping_ht_cents'], (int) $order['shipping_ttc_cents'],
                    (string) $order['shipping_tax_code'], (int) $order['shipping_tax_bp'], self::defaultComponents((string) $order['shipping_tax_code'], (int) $order['shipping_tax_bp']));
            }
        } else {
            $ttc = isset($shipping['price_cents']) ? (int) $shipping['price_cents'] : self::cents($shipping['price'] ?? 0);
            if ($ttc > 0) {
                $lines[] = self::shippingLine($shipping, ['rate' => $legacyTax, 'ht' => $legacyTax->removeFrom(Money::of($ttc, $currency))->amount, 'ttc' => $ttc], false);
            }
        }

        return self::assemble($lines, $currency);
    }

    /**
     * Totaux de facture : par taxe élémentaire (catégorie, taux, nom), totaux d'en-tête et montant d'arrondi.
     * `en16931` faux si une ligne porte plusieurs taxes ou une taxe composée (non exprimable en facture électronique EN 16931).
     *
     * @return array{taxes: list<array{code: string, name: string, bp: int, category: string, mention: string, base_cents: int, tax_cents: int}>,
     *               line_total_ht_cents: int, tax_total_cents: int, grand_total_cents: int, rounding_cents: int, due_cents: int,
     *               en16931: bool, mentions: list<string>}
     */
    public static function invoiceTotals(array $amounts): array
    {
        $groups = [];
        $simple = true;
        $lineTotal = 0;
        foreach ($amounts['lines'] as $line) {
            $lineTotal += $line['line_ht_cents'];
            $components = array_map([TaxComponent::class, 'fromArray'], $line['taxes']);
            if (count($components) !== 1 || $components[0]->compound) {
                $simple = false;
                foreach ((new TaxRate($line['tax_code'] ?: 'x', min(9999, $line['tax_bp']), $components))->breakdown(Money::of($line['line_ht_cents'], $amounts['currency'])) as $b) {
                    $g = &self::group($groups, $b['component']);
                    $g['base_cents'] += $line['line_ht_cents'];
                    $g['fixed_tax'] += $b['amount'];
                    unset($g);
                }
                continue;
            }
            $g = &self::group($groups, $components[0]);
            $g['base_cents'] += $line['line_ht_cents'];
            $g['simple_base'] += $line['line_ht_cents'];
            unset($g);
        }
        $taxTotal = 0;
        $mentions = [];
        foreach ($groups as &$g) {
            $g['tax_cents'] = (new TaxRate('x', $g['bp']))->taxOn(Money::of($g['simple_base'], $amounts['currency']))->amount + $g['fixed_tax'];
            $taxTotal += $g['tax_cents'];
            if ($g['mention'] !== '') {
                $mentions[] = $g['mention'];
            }
            unset($g['simple_base'], $g['fixed_tax']);
        }
        unset($g);
        $grand = $lineTotal + $taxTotal;

        return [
            'taxes'               => array_values($groups),
            'line_total_ht_cents' => $lineTotal,
            'tax_total_cents'     => $taxTotal,
            'grand_total_cents'   => $grand,
            'rounding_cents'      => $amounts['total_ttc_cents'] - $grand,
            'due_cents'           => $amounts['total_ttc_cents'],
            'en16931'             => $simple,
            'mentions'            => array_values(array_unique($mentions)),
        ];
    }

    /** Montant décimal (affichage, anciennes colonnes REAL) d'un nombre de centimes. */
    public static function toDecimal(int $cents): float
    {
        return $cents / 100;
    }

    private static function &group(array &$groups, TaxComponent $c): array
    {
        $k = $c->category . '|' . $c->basisPoints . '|' . $c->name;
        $groups[$k] ??= ['code' => $c->name, 'name' => $c->name, 'bp' => $c->basisPoints, 'category' => $c->category, 'mention' => $c->mention,
            'base_cents' => 0, 'tax_cents' => 0, 'simple_base' => 0, 'fixed_tax' => 0];

        return $groups[$k];
    }

    /** Composante unique d'une ancienne ligne (code + taux, sans détail). */
    private static function defaultComponents(string $code, int $bp): array
    {
        return [(new TaxComponent($code !== '' ? $code : 'TVA', $bp, false, true, $bp > 0 ? 'S' : 'Z'))->jsonSerialize()];
    }

    /** @param array{rate: ?TaxRate, ht: int, ttc: int} $part */
    private static function shippingLine(array $shipping, array $part, bool $split): array
    {
        $rate = $part['rate'];
        $title = self::shippingTitle($shipping) . ($split && $rate ? ' (part ' . $rate->components[0]->name . ')' : '');
        $components = $rate ? array_map(static fn ($c) => $c->jsonSerialize(), $rate->components)
            : [(new TaxComponent('Non taxé', 0, false, true, 'O'))->jsonSerialize()];

        return self::line('shipping', $title, 1, $part['ht'], $part['ttc'], $rate?->code ?? 'non-taxe', $rate?->basisPoints ?? 0, $components);
    }

    private static function shippingTitle(array $shipping): string
    {
        $name = (string) ($shipping['name'] ?? $shipping['label'] ?? '');

        return $name !== '' ? 'Livraison : ' . $name : 'Frais de livraison';
    }

    private static function line(string $kind, string $title, int $qty, int $unitHt, int $unitTtc, string $code, int $bp, array $taxes, string $route = '',
        string $lineId = '', array $options = []): array
    {
        return [
            'kind'           => $kind,
            'route'          => $route,
            'line_id'        => $lineId,
            'options'        => $options,
            'title'          => $title,
            'quantity'       => $qty,
            'unit_ht_cents'  => $unitHt,
            'unit_ttc_cents' => $unitTtc,
            'line_ht_cents'  => $unitHt * $qty,
            'line_ttc_cents' => $unitTtc * $qty,
            'tax_code'       => $code,
            'tax_bp'         => $bp,
            'taxes'          => array_values(array_map(static fn ($c) => $c instanceof TaxComponent ? $c->jsonSerialize() : (array) $c, $taxes)),
        ];
    }

    private static function assemble(array $lines, string $currency): array
    {
        $items = 0;
        $itemsHt = 0;
        $shippingLines = [];
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['line_ttc_cents'];
            if ($l['kind'] === 'item') {
                $items += $l['line_ttc_cents'];
                $itemsHt += $l['line_ht_cents'];
            } else {
                $shippingLines[] = $l;
            }
        }

        return [
            'currency'           => $currency,
            'lines'              => $lines,
            'items_ttc_cents'    => $items,
            'items_ht_cents'     => $itemsHt,
            'shipping'           => $shippingLines[0] ?? null,
            'shipping_lines'     => $shippingLines,
            'shipping_ttc_cents' => array_sum(array_column($shippingLines, 'line_ttc_cents')),
            'shipping_ht_cents'  => array_sum(array_column($shippingLines, 'line_ht_cents')),
            'total_ttc_cents'    => $total,
        ];
    }

    /** Ancien montant décimal (float ou chaîne) → centimes, arrondi au plus proche. */
    private static function cents($value): int
    {
        return is_numeric($value) && (float) $value > 0 ? Money::fromLegacyDecimal($value, 'EUR')->amount : 0;
    }
}
