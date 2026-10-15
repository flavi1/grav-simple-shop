<?php

namespace Grav\Plugin\SimpleShop\Tax;

/**
 * Réglages des taxes (`plugins.simple-shop.taxes`), normalisés. Modèle de WooCommerce :
 *
 *  - **classes** de taxe (ex. `standard`, `reduit`) attachées aux types de produits, jamais un taux ;
 *  - **table des taux** : pour chaque classe, des lignes pays / région / codes postaux / taux / nom / priorité / composée /
 *    s'applique à la livraison / catégorie de facture / mention ;
 *  - réglages : prix saisis HT ou TTC, adresse qui décide du taux, taxe de la livraison, franchise en base.
 *
 * Valeurs par défaut : TVA française (20 / 10 / 5,5 / 2,1 / 0 %, exonéré). L'ancienne configuration (`taxes.default` +
 * `taxes.rates` sous forme `CODE: {label, rate}`) est reprise : chaque ancien code devient une classe à un taux (France).
 */
final class TaxSettings
{
    public const BASED_ON = ['shipping', 'billing', 'shop'];

    /** `items` : selon les articles (panier mixte : taux le plus élevé, comme WooCommerce) ; `items_prorata` : réparti au prorata HT. */
    public const SHIPPING_MODES = ['items', 'items_prorata'];

    public const DEFAULT_CLASSES = [
        'standard'      => 'Taux normal',
        'intermediaire' => 'Taux intermédiaire',
        'reduit'        => 'Taux réduit',
        'particulier'   => 'Taux particulier',
        'zero'          => 'Taux zéro',
        'exonere'       => 'Exonéré',
    ];

    public const DEFAULT_RATES = [
        ['class' => 'standard', 'country' => 'FR', 'rate' => 20, 'name' => 'TVA 20 %', 'category' => 'S'],
        ['class' => 'intermediaire', 'country' => 'FR', 'rate' => 10, 'name' => 'TVA 10 %', 'category' => 'S'],
        ['class' => 'reduit', 'country' => 'FR', 'rate' => 5.5, 'name' => 'TVA 5,5 %', 'category' => 'S'],
        ['class' => 'particulier', 'country' => 'FR', 'rate' => 2.1, 'name' => 'TVA 2,1 %', 'category' => 'S'],
        ['class' => 'zero', 'country' => 'FR', 'rate' => 0, 'name' => 'TVA 0 %', 'category' => 'Z'],
        ['class' => 'exonere', 'country' => 'FR', 'rate' => 0, 'name' => 'Exonéré', 'category' => 'E', 'mention' => 'TVA non applicable'],
    ];

    /**
     * @return array{enabled: bool, prices_entered: string, based_on: string, shipping_tax: string, shop: array{country: string, region: string, postcode: string},
     *               default_class: string, small_business: array{enabled: bool, mention: string}, classes: array<string, string>,
     *               rates: list<array>, types: array<string, string>, legacy_codes: array<string, string>}
     */
    public static function fromConfig(array $config): array
    {
        $t = is_array($config['taxes'] ?? null) ? $config['taxes'] : [];
        $legacyCodes = [];

        // Classes et taux : nouveau format (liste de lignes avec `class`), ou ancien (`CODE: {label, rate}`).
        $classes = [];
        foreach ((array) ($t['classes'] ?? []) as $code => $label) {
            if (is_array($label)) {   // liste Admin2 : [{code, label}]
                [$code, $label] = [(string) ($label['code'] ?? ''), (string) ($label['label'] ?? '')];
            }
            $code = self::code((string) $code);
            if ($code !== '') {
                $classes[$code] = trim((string) $label) !== '' ? trim((string) $label) : $code;
            }
        }
        $rawRates = $t['rates'] ?? null;
        $rows = [];
        if (is_array($rawRates) && $rawRates !== [] && !array_is_list($rawRates)) {
            foreach ($rawRates as $code => $def) {
                if (!is_array($def) || !isset($def['rate']) || !is_numeric($def['rate'])) {
                    continue;
                }
                $code = self::code((string) $code);
                $rate = (float) $def['rate'];
                $rate = $rate <= 1 ? $rate * 100 : $rate;
                $classes[$code] ??= (string) ($def['label'] ?? $code);
                $rows[] = ['class' => $code, 'country' => 'FR', 'rate' => $rate, 'name' => (string) ($def['label'] ?? $code), 'category' => $rate > 0 ? 'S' : 'Z'];
                $legacyCodes[$code] = $code;
            }
        } elseif (is_array($rawRates)) {
            $rows = $rawRates;
        }
        if ($rows === []) {
            $rows = self::DEFAULT_RATES;
            $classes += self::DEFAULT_CLASSES;
        }

        $rates = [];
        foreach ($rows as $row) {
            $n = self::normalizeRate($row);
            if ($n !== null) {
                $rates[] = $n;
                $classes[$n['class']] ??= $n['class'];
            }
        }


        // Type de produit → classe (surcharge du blueprint) : map `book: reduit` ou liste Admin2 [{type, class}].
        $types = [];
        foreach ((array) ($t['types'] ?? []) as $type => $class) {
            if (is_array($class)) {
                [$type, $class] = [(string) ($class['type'] ?? ''), (string) ($class['class'] ?? '')];
            }
            if ((string) $type !== '' && isset($classes[self::code((string) $class)])) {
                $types[(string) $type] = self::code((string) $class);
            }
        }

        // Anciens codes « TVA_5_5 » (blueprints, commandes) : classe française de même taux.
        foreach ($rates as $r) {
            if ($r['country'] === 'FR' && $r['category'] !== 'E') {
                $legacy = 'TVA_' . str_replace('.', '_', rtrim(rtrim(number_format($r['bp'] / 100, 2, '.', ''), '0'), '.'));
                $legacyCodes[$legacy] ??= $r['class'];
            }
        }

        // Classe par défaut (un ancien code « TVA_5_5 » désigne la classe française de même taux).
        $default = self::code((string) ($t['default_class'] ?? $t['default'] ?? ''));
        $default = $legacyCodes[$default] ?? $default;
        if (!isset($classes[$default])) {
            $default = isset($classes['standard']) ? 'standard' : (string) array_key_first($classes);
        }
        foreach ($types as $type => $class) {
            $types[$type] = $legacyCodes[$class] ?? $class;
        }

        $shop = is_array($t['shop'] ?? null) ? $t['shop'] : [];
        $sb = is_array($t['small_business'] ?? null) ? $t['small_business'] : [];
        $basedOn = (string) ($t['based_on'] ?? 'shipping');
        $shipping = (string) ($t['shipping_tax'] ?? 'items');
        if (!in_array($shipping, self::SHIPPING_MODES, true) && !isset($classes[self::code($shipping)])) {
            $shipping = 'items';
        }

        return [
            'enabled'        => !array_key_exists('enabled', $t) || !empty($t['enabled']),
            'prices_entered' => strtolower((string) ($t['prices_entered'] ?? 'ht')) === 'ttc' ? 'ttc' : 'ht',
            'based_on'       => in_array($basedOn, self::BASED_ON, true) ? $basedOn : 'shipping',
            'shipping_tax'   => in_array($shipping, self::SHIPPING_MODES, true) ? $shipping : self::code($shipping),
            'shop'           => [
                'country'  => self::country((string) ($shop['country'] ?? $config['seller']['country'] ?? 'FR')) ?: 'FR',
                'region'   => strtoupper(trim((string) ($shop['region'] ?? ''))),
                'postcode' => trim((string) ($shop['postcode'] ?? $config['seller']['postal_code'] ?? '')),
            ],
            'default_class'  => $default,
            'small_business' => [
                'enabled' => !empty($sb['enabled']),
                'mention' => trim((string) ($sb['mention'] ?? '')) ?: 'TVA non applicable, art. 293 B du CGI',
            ],
            'classes'        => $classes,
            'rates'          => $rates,
            'types'          => $types,
            'legacy_codes'   => $legacyCodes,
        ];
    }

    /** Ligne de la table des taux normalisée, ou null si inutilisable. */
    public static function normalizeRate($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $class = self::code((string) ($row['class'] ?? ''));
        // Taux en % (« 5.5 », « 5,5 ») ; une ligne déjà normalisée (événement onSimpleShopTaxRates) porte `bp`.
        $rate = isset($row['bp']) && !isset($row['rate']) ? (string) ((int) $row['bp'] / 100) : str_replace(',', '.', (string) ($row['rate'] ?? ''));
        if ($class === '' || !is_numeric($rate) || (float) $rate < 0 || (float) $rate >= 100) {
            return null;
        }
        $bp = (int) round((float) $rate * 100);
        $category = strtoupper(trim((string) ($row['category'] ?? '')));
        if (!in_array($category, \Grav\Plugin\SimpleShop\Pricing\TaxComponent::CATEGORIES, true)) {
            $category = $bp > 0 ? 'S' : 'Z';
        }
        $postcodes = $row['postcodes'] ?? $row['postcode'] ?? '';
        $postcodes = is_array($postcodes) ? $postcodes : preg_split('/[;,\s]+/', (string) $postcodes);

        return [
            'class'     => $class,
            'country'   => self::country((string) ($row['country'] ?? '')),
            'region'    => strtoupper(trim((string) ($row['region'] ?? $row['state'] ?? ''))),
            'postcodes' => array_values(array_filter(array_map('trim', $postcodes), 'strlen')),
            'bp'        => $bp,
            'name'      => trim((string) ($row['name'] ?? '')) ?: rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',') . ' %',
            'priority'  => max(1, (int) ($row['priority'] ?? 1)),
            'compound'  => !empty($row['compound']),
            'shipping'  => !array_key_exists('shipping', $row) || !empty($row['shipping']),
            'category'  => $category,
            'mention'   => trim((string) ($row['mention'] ?? '')),
        ];
    }

    /** Code de classe : minuscules, chiffres, `_` et `-` (les anciens codes « TVA_5_5 » sont gardés tels quels). */
    public static function code(string $code): string
    {
        $code = trim($code);

        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code) ? $code : '';
    }

    private static function country(string $c): string
    {
        $c = strtoupper(trim($c));

        return preg_match('/^[A-Z]{2}$/', $c) ? $c : '';
    }

    /** Options des listes de l'administration (blueprint `data-options@`) : classes de taxe. */
    public static function classOptions(): array
    {
        try {
            $config = (array) \Grav\Common\Grav::instance()['config']->get('plugins.simple-shop', []);
        } catch (\Throwable $e) {
            $config = [];
        }

        return self::fromConfig($config)['classes'];
    }

    /** Options : modes de taxe de la livraison (dont « classe fixe »). */
    public static function shippingOptions(): array
    {
        $opts = [
            'items'         => 'Selon les articles du panier (panier mixte : taux le plus élevé, comme WooCommerce)',
            'items_prorata' => 'Selon les articles du panier, réparti au prorata du HT (pratique française recommandée)',
        ];
        foreach (self::classOptions() as $code => $label) {
            $opts[$code] = 'Toujours la classe « ' . $label . ' »';
        }

        return $opts;
    }

    /** Options : types de produits découverts dans les blueprints. */
    public static function typeOptions(): array
    {
        $out = [];
        try {
            foreach (array_keys(\Grav\Plugin\SimpleShop\Product\ProductTypes::all()) as $name) {
                $out[$name] = $name;
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
