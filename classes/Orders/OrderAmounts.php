<?php

namespace Grav\Plugin\SimpleShop\Orders;

use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Pricing\TaxRate;

/**
 * Montants d'une commande, en centimes entiers : une seule source pour le panier, le paiement, la commande enregistrée
 * et la facture.
 *
 * Une **ligne** = produit (ou frais de livraison) avec ses prix unitaires HT et TTC, son code de taxe, son taux en
 * points de base et sa quantité. Le **total payé** est la somme des TTC des lignes : c'est le montant encaissé.
 *
 * La **facture** (EN 16931) regroupe les lignes par taux : base HT = somme des HT, TVA = arrondi(base × taux),
 * total TTC = base + TVA. L'écart éventuel avec le montant encaissé (quelques centimes) est le montant d'arrondi BT-114.
 *
 * Les commandes enregistrées avant les centimes (colonnes vides) sont relues depuis leurs anciens montants décimaux TTC
 * et le taux unique figé sur leur facture (ou le taux du site).
 */
final class OrderAmounts
{
    /**
     * Montants du panier en cours.
     *
     * @param array $cartItems Lignes de CartStorage::getCartItems() (clés `unit_ttc_cents`, `unit_ht_cents`, `tax_code`, `tax_bp`…).
     * @param array $shipping  Livraison choisie (`price_cents`, sinon ancien `price` décimal TTC ; `name`).
     * @param TaxRate $shippingTax Taxe des frais de livraison (taux du site).
     */
    public static function fromCart(array $cartItems, array $shipping, TaxRate $shippingTax, string $currency): array
    {
        $lines = [];
        foreach ($cartItems as $key => $item) {
            $route = (string) ($item['route'] ?? $key);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $ttc = isset($item['unit_ttc_cents']) ? (int) $item['unit_ttc_cents'] : self::cents($item['price'] ?? 0);
            $bp = isset($item['tax_bp']) ? (int) $item['tax_bp'] : $shippingTax->basisPoints;
            $ht = isset($item['unit_ht_cents']) ? (int) $item['unit_ht_cents'] : (new TaxRate('', $bp))->removeFrom(Money::of($ttc, $currency))->amount;
            $lines[] = self::line('item', (string) ($item['title'] ?? $route), $qty, $ht, $ttc, (string) ($item['tax_code'] ?? $shippingTax->code), $bp, $route,
                (string) ($item['line_id'] ?? $key), (array) ($item['options'] ?? []));
        }
        $shipLine = self::shippingLine($shipping, $shippingTax, $currency);
        if ($shipLine !== null) {
            $lines[] = $shipLine;
        }

        return self::assemble($lines, $currency);
    }

    /** Montants du panier avec la devise et la taxe de livraison du site. */
    public static function forSite(array $cartItems, array $shipping): array
    {
        return self::fromCart($cartItems, $shipping, \Grav\Plugin\SimpleShop\Product\Catalog::taxRate(), \Grav\Plugin\SimpleShop\Product\Catalog::currency());
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
            $options = json_decode((string) ($item['options'] ?? '{}'), true);
            $lines[] = self::line('item', (string) ($item['title'] ?? ''), $qty, $ht, $ttc, $code, $bp, (string) ($item['product_route'] ?? ''),
                (string) ($item['line_id'] ?? ''), is_array($options) ? $options : []);
        }

        $shipping = json_decode((string) ($order['shipping_data'] ?? '{}'), true) ?: [];
        if (isset($order['shipping_ttc_cents']) && $order['shipping_ttc_cents'] !== null && $order['shipping_ttc_cents'] !== '') {
            if ((int) $order['shipping_ttc_cents'] > 0) {
                $lines[] = self::line('shipping', self::shippingTitle($shipping), 1, (int) $order['shipping_ht_cents'], (int) $order['shipping_ttc_cents'],
                    (string) $order['shipping_tax_code'], (int) $order['shipping_tax_bp']);
            }
        } else {
            $shipLine = self::shippingLine($shipping, $legacyTax, $currency);
            if ($shipLine !== null) {
                $lines[] = $shipLine;
            }
        }

        return self::assemble($lines, $currency);
    }

    /**
     * Totaux de facture EN 16931 : par taux (base HT, TVA), totaux d'en-tête et montant d'arrondi.
     *
     * @return array{taxes: list<array{code: string, bp: int, base_cents: int, tax_cents: int}>, line_total_ht_cents: int,
     *               tax_total_cents: int, grand_total_cents: int, rounding_cents: int, due_cents: int}
     */
    public static function invoiceTotals(array $amounts): array
    {
        $groups = [];
        foreach ($amounts['lines'] as $line) {
            $k = $line['tax_bp'] . '|' . $line['tax_code'];
            $groups[$k] ??= ['code' => $line['tax_code'], 'bp' => $line['tax_bp'], 'base_cents' => 0, 'tax_cents' => 0];
            $groups[$k]['base_cents'] += $line['line_ht_cents'];
        }
        $lineTotal = 0;
        $taxTotal = 0;
        foreach ($groups as &$g) {
            $g['tax_cents'] = (new TaxRate($g['code'], $g['bp']))->taxOn(Money::of($g['base_cents'], $amounts['currency']))->amount;
            $lineTotal += $g['base_cents'];
            $taxTotal += $g['tax_cents'];
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
        ];
    }

    /** Montant décimal (affichage, anciennes colonnes REAL) d'un nombre de centimes. */
    public static function toDecimal(int $cents): float
    {
        return $cents / 100;
    }

    private static function shippingLine(array $shipping, TaxRate $tax, string $currency): ?array
    {
        $ttc = isset($shipping['price_cents']) ? (int) $shipping['price_cents'] : self::cents($shipping['price'] ?? 0);
        if ($ttc <= 0) {
            return null;
        }

        return self::line('shipping', self::shippingTitle($shipping), 1, $tax->removeFrom(Money::of($ttc, $currency))->amount, $ttc, $tax->code, $tax->basisPoints);
    }

    private static function shippingTitle(array $shipping): string
    {
        $name = (string) ($shipping['name'] ?? $shipping['label'] ?? '');

        return $name !== '' ? 'Livraison : ' . $name : 'Frais de livraison';
    }

    private static function line(string $kind, string $title, int $qty, int $unitHt, int $unitTtc, string $code, int $bp, string $route = '',
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
        ];
    }

    private static function assemble(array $lines, string $currency): array
    {
        $items = 0;
        $itemsHt = 0;
        $shipping = null;
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['line_ttc_cents'];
            if ($l['kind'] === 'item') {
                $items += $l['line_ttc_cents'];
                $itemsHt += $l['line_ht_cents'];
            } else {
                $shipping = $l;
            }
        }

        return [
            'currency'           => $currency,
            'lines'              => $lines,
            'items_ttc_cents'    => $items,
            'items_ht_cents'     => $itemsHt,
            'shipping'           => $shipping,
            'shipping_ttc_cents' => $shipping['line_ttc_cents'] ?? 0,
            'total_ttc_cents'    => $total,
        ];
    }

    /** Ancien montant décimal (float ou chaîne) → centimes, arrondi au plus proche. */
    private static function cents($value): int
    {
        return is_numeric($value) && (float) $value > 0 ? Money::fromLegacyDecimal($value, 'EUR')->amount : 0;
    }
}
