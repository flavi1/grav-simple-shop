<?php

namespace Grav\Plugin\SimpleShop\Tax;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Pricing\Money;
use Grav\Plugin\SimpleShop\Pricing\TaxComponent;
use Grav\Plugin\SimpleShop\Pricing\TaxRate;

/**
 * Taxe d'un type de taxe (ex. « Taux réduit ») à une adresse, et taxe des frais de livraison (voir TaxSettings).
 *
 * Résolution (comme WooCommerce) : parmi les taux de ce type, pour chaque priorité, la ligne la plus précise qui
 * correspond à l'adresse (code postal > région > pays > toutes) ; les taux retenus s'additionnent, les « composés »
 * s'appliquent ensuite. Aucune ligne : pas de taxe.
 *
 * Point d'extension : l'événement `onSimpleShopTaxRates` (paramètres `rates`, `settings`) permet à un plugin de modifier
 * la table des taux une fois par requête (ex. un service de taxes externe, des taux par État américain).
 */
final class TaxResolver
{
    /** @var array<string, array> Réglages normalisés par configuration (clé : empreinte), pour la requête. */
    private static array $settings = [];

    public static function reset(): void
    {
        self::$settings = [];
    }

    /** Réglages normalisés : ceux du site, ou ceux d'une configuration donnée (tests, simulateur) ; mis en cache pour la requête. */
    public static function settings(?array $config = null): array
    {
        if ($config === null) {
            try {
                $config = (array) Grav::instance()['config']->get('plugins.simple-shop', []);
            } catch (\Throwable $e) {
                $config = [];
            }
        }
        $key = md5(serialize($config['taxes'] ?? null) . '|' . serialize($config['seller']['country'] ?? null));
        if (!isset(self::$settings[$key])) {
            $settings = TaxSettings::fromConfig($config);
            try {
                $rates = $settings['rates'];
                Grav::instance()->fireEvent('onSimpleShopTaxRates', new \RocketTheme\Toolbox\Event\Event(['rates' => &$rates, 'settings' => $settings]));
                $out = [];
                foreach ((array) $rates as $row) {
                    $n = TaxSettings::normalizeRate($row);
                    if ($n !== null) {
                        $n['key'] = $settings['aliases'][$n['key']] ?? $n['key'];
                        $out[] = $n;
                    }
                }
                $settings['rates'] = $out;
            } catch (\Throwable $e) {
            }
            self::$settings[$key] = $settings;
        }

        return self::$settings[$key];
    }

    /**
     * Adresse qui décide du taux : celle de la boutique, ou celle du client (livraison ou facturation) dès qu'elle est
     * connue dans la commande en cours ; avant (fiche produit, panier), celle de la boutique.
     */
    public static function location(?array $settings = null): TaxLocation
    {
        $s = $settings ?? self::settings();
        $shop = new TaxLocation($s['shop']['country'], $s['shop']['region'], $s['shop']['postcode']);
        if ($s['based_on'] === 'shop') {
            return $shop;
        }
        try {
            $order = Grav::instance()['session']->shop_order ?? null;
        } catch (\Throwable $e) {
            $order = null;
        }
        $address = $s['based_on'] === 'billing' && !empty($order->billing) ? (array) $order->billing : (array) ($order->customer ?? []);
        $postcode = trim((string) ($address['postal-code'] ?? $address['postal_code'] ?? ''));
        if ($postcode === '' && empty($address['country'])) {
            return $shop;
        }

        return new TaxLocation((string) ($address['country'] ?? $s['shop']['country']), (string) ($address['region'] ?? ''), $postcode);
    }

    /** Clé du type de taxe désigné par un nom, une clé ou un ancien code (« reduit », « TVA_5_5 ») ; '' si inconnu. */
    public static function resolve(string $ref, ?array $config = null): string
    {
        $s = self::settings($config);
        $k = TaxSettings::key($ref);
        $k = $s['aliases'][$k] ?? $k;

        return isset($s['names'][$k]) ? $k : '';
    }

    /** Taxe d'un type de taxe (nom ou clé ; inconnu : type par défaut) à une adresse (par défaut : location()). */
    public static function forRate(string $name, ?TaxLocation $location = null, ?array $config = null): TaxRate
    {
        $s = self::settings($config);
        if (!$s['enabled']) {
            return new TaxRate('sans-taxe', 0, [new TaxComponent('Sans taxe', 0, false, true, 'O')]);
        }
        if ($s['small_business']['enabled']) {
            return new TaxRate('franchise', 0, [new TaxComponent('Franchise en base', 0, false, true, 'E', $s['small_business']['mention'])]);
        }
        $key = self::resolve($name, $config) ?: $s['default_rate'];
        $location ??= self::location($s);

        $best = [];
        foreach ($s['rates'] as $row) {
            if ($row['key'] !== $key) {
                continue;
            }
            $score = $location->match($row);
            if ($score === null) {
                continue;
            }
            $p = $row['priority'];
            if (!isset($best[$p]) || $score > $best[$p][0]) {
                $best[$p] = [$score, $row];
            }
        }
        ksort($best);
        $components = [];
        foreach ($best as [, $row]) {
            $components[] = new TaxComponent($row['label'], $row['bp'], $row['compound'], $row['shipping'], $row['category'], $row['mention']);
        }
        if ($components === []) {
            return new TaxRate($key, 0, [new TaxComponent('Sans taxe', 0, false, true, 'O')]);
        }

        return count($components) === 1 && !$components[0]->compound
            ? new TaxRate($key, $components[0]->basisPoints, $components)
            : TaxRate::fromComponents($key, $components);
    }

    /** Type de taxe d'un produit (clé) : fiche (`tax_type`) > administration (`product_types`) > blueprint (`shop.tax`) > type par défaut. */
    public static function rateFor(?string $typeName, ?string $blueprintTax = null, array $header = [], ?array $config = null): string
    {
        $s = self::settings($config);
        foreach ([(string) (($header['tax_type'] ?? '') ?: ($header['tax_rate'] ?? '') ?: ($header['tax_class'] ?? '')), $s['types'][(string) $typeName] ?? '', (string) $blueprintTax] as $candidate) {
            $k = $candidate !== '' ? self::resolve($candidate, $config) : '';
            if ($k !== '') {
                return $k;
            }
        }

        return $s['default_rate'];
    }

    /**
     * Taxe(s) des frais de livraison d'un colis, d'après le taux de ses articles expédiés.
     *
     * @param array<string, int> $mix Clé de taux → montant HT (centimes) des articles expédiés.
     *
     * @return list<array{rate: ?TaxRate, weight: int}> Une part par taxe ; `rate` null = part non taxée.
     */
    public static function shippingParts(array $mix, ?TaxLocation $location = null, ?array $config = null): array
    {
        $s = self::settings($config);
        $mix = array_filter($mix, static fn ($v) => (int) $v > 0);
        $mode = $s['shipping_tax'];
        if (!in_array($mode, TaxSettings::SHIPPING_MODES, true)) {
            return [['rate' => self::forRate($mode, $location, $config)->forShipping(), 'weight' => 1]];
        }
        if ($mix === []) {
            $mix = [$s['default_rate'] => 1];
        }
        $parts = [];
        foreach ($mix as $key => $ht) {
            $parts[] = ['rate' => self::forRate((string) $key, $location, $config)->forShipping(), 'weight' => (int) $ht];
        }
        if ($mode === 'items') {
            usort($parts, static fn ($a, $b) => ($b['rate']?->basisPoints ?? -1) <=> ($a['rate']?->basisPoints ?? -1));

            return [['rate' => $parts[0]['rate'], 'weight' => 1]];
        }

        // Prorata : les taux de même taxe sont regroupés.
        $merged = [];
        foreach ($parts as $p) {
            $key = $p['rate'] ? $p['rate']->code . '|' . $p['rate']->basisPoints : '-';
            $merged[$key] = isset($merged[$key]) ? ['rate' => $p['rate'], 'weight' => $merged[$key]['weight'] + $p['weight']] : $p;
        }

        return array_values($merged);
    }

    /** HT réparti entre les parts au prorata de leur poids (centimes exacts : le reste va aux plus grosses fractions). */
    public static function splitHt(int $ht, array $parts): array
    {
        $total = array_sum(array_column($parts, 'weight'));
        if ($total <= 0) {
            return [];
        }
        $shares = [];
        $fractions = [];
        $given = 0;
        foreach ($parts as $i => $p) {
            $exact = $ht * $p['weight'] / $total;
            $shares[$i] = (int) floor($exact);
            $fractions[$i] = $exact - $shares[$i];
            $given += $shares[$i];
        }
        arsort($fractions);
        foreach (array_keys($fractions) as $i) {
            if ($given >= $ht) {
                break;
            }
            $shares[$i]++;
            $given++;
        }
        $out = [];
        foreach ($parts as $i => $p) {
            $out[] = ['rate' => $p['rate'], 'ht' => $shares[$i]];
        }

        return $out;
    }

    /** TTC (centimes) de frais de livraison saisis HT. */
    public static function shippingTtcFromHt(int $ht, array $parts, string $currency = 'EUR'): int
    {
        $ttc = 0;
        foreach (self::splitHt($ht, $parts) as $p) {
            $ttc += $p['rate'] ? $p['rate']->addTo(Money::of($p['ht'], $currency))->amount : $p['ht'];
        }

        return $parts === [] ? $ht : $ttc;
    }

    /**
     * Frais de livraison TTC (montant payé) répartis entre les parts : HT de chaque part au prorata, TTC exact au total
     * (le centime d'écart éventuel va à la plus grosse part).
     *
     * @return list<array{rate: ?TaxRate, ht: int, ttc: int}>
     */
    public static function splitShippingTtc(int $ttc, array $parts, string $currency = 'EUR'): array
    {
        if ($parts === [] || $ttc <= 0) {
            return [];
        }
        $total = array_sum(array_column($parts, 'weight'));
        // HT total H tel que Σ part_i (H × poids_i) × (1 + taux_i) = TTC.
        $factor = 0.0;
        foreach ($parts as $p) {
            $factor += ($p['weight'] / $total) * (1 + ($p['rate'] ? $p['rate']->basisPoints : 0) / 10000);
        }
        $out = [];
        foreach (self::splitHt((int) round($ttc / $factor), $parts) as $p) {
            $out[] = $p + ['ttc' => $p['rate'] ? $p['rate']->addTo(Money::of($p['ht'], $currency))->amount : $p['ht']];
        }
        $gap = $ttc - array_sum(array_column($out, 'ttc'));
        if ($gap !== 0) {
            usort($out, static fn ($a, $b) => $b['ttc'] <=> $a['ttc']);
            $out[0]['ttc'] += $gap;
        }

        return array_values(array_filter($out, static fn ($p) => $p['ttc'] > 0 || $p['ht'] > 0));
    }
}
