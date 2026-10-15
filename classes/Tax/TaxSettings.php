<?php

namespace Grav\Plugin\SimpleShop\Tax;

/**
 * Réglages des taxes (`plugins.simple-shop.taxes`), normalisés.
 *
 *  - des **types de taxe** (`taxes.tax_types`, ex. « Taux réduit ») : c'est le type que choisissent les types de produits
 *    (`taxes.product_types`, blueprint `shop.tax`) et les fiches (`tax_type`) ;
 *  - chaque type contient **un ou plusieurs taux** : taux, pays (vide = partout), région, codes postaux, libellé de facture,
 *    priorité, composée, s'applique à la livraison, catégorie de facture électronique, mention (exonération). Plusieurs taux
 *    = variantes par pays / région / codes postaux (vente à l'étranger, DOM) ou taxes cumulées (priorités) : à l'adresse du
 *    client, on retient à chaque priorité le taux le plus précis ;
 *  - réglages : prix saisis HT ou TTC, adresse qui décide du taux, taxe de la livraison, franchise en base.
 *
 * Valeurs par défaut : TVA française (20 / 10 / 5,5 / 2,1 / 0 %, exonéré). Formats antérieurs repris :
 *  - table plate `taxes.rates` dont chaque ligne porte le nom de son type (`name`) ;
 *  - `classes` + lignes `class` : le libellé de la classe devient le nom du type, l'ancien nom de ligne son libellé ;
 *  - `taxes.default` + `taxes.rates` sous forme `CODE: {label, rate}`.
 *
 * En interne, les taux sont une liste plate ; chacun porte la **clé** de son type : le nom en minuscules sans accents
 * (« taux-reduit »). Les anciens codes (`reduit`, `standard`, `TVA_5_5`…) restent compris (alias).
 */
final class TaxSettings
{
    public const BASED_ON = ['shipping', 'billing', 'shop'];

    /** `items` : selon les articles (panier mixte : taux le plus élevé, comme WooCommerce) ; `items_prorata` : réparti au prorata HT. */
    public const SHIPPING_MODES = ['items', 'items_prorata'];

    /** Anciens codes de classe → nom du taux. */
    public const LEGACY_NAMES = [
        'standard'      => 'Taux normal',
        'intermediaire' => 'Taux intermédiaire',
        'reduit'        => 'Taux réduit',
        'particulier'   => 'Taux particulier',
        'zero'          => 'Taux zéro',
        'exonere'       => 'Exonéré',
    ];

    public const DEFAULT_RATES = [
        ['name' => 'Taux normal', 'country' => 'FR', 'rate' => 20, 'label' => 'TVA 20 %', 'category' => 'S'],
        ['name' => 'Taux intermédiaire', 'country' => 'FR', 'rate' => 10, 'label' => 'TVA 10 %', 'category' => 'S'],
        ['name' => 'Taux réduit', 'country' => 'FR', 'rate' => 5.5, 'label' => 'TVA 5,5 %', 'category' => 'S'],
        ['name' => 'Taux particulier', 'country' => 'FR', 'rate' => 2.1, 'label' => 'TVA 2,1 %', 'category' => 'S'],
        ['name' => 'Taux zéro', 'country' => 'FR', 'rate' => 0, 'label' => 'TVA 0 %', 'category' => 'Z'],
        ['name' => 'Exonéré', 'country' => 'FR', 'rate' => 0, 'label' => 'Exonéré', 'category' => 'E', 'mention' => 'TVA non applicable'],
    ];

    public const DEFAULT_RATE = 'Taux normal';

    /** Champs d'un taux (dans un type de taxe), dans l'ordre de l'administration et du CSV. */
    public const RATE_FIELDS = ['rate', 'country', 'region', 'postcodes', 'label', 'priority', 'compound', 'shipping', 'category', 'mention'];

    /** Types de taxe par défaut, imbriqués (format de `taxes.tax_types`). */
    public static function defaultTaxTypes(): array
    {
        return self::nest(array_map(static fn ($r) => self::normalizeRate($r), self::DEFAULT_RATES));
    }

    /**
     * Taux normalisés (liste plate) → types de taxe imbriqués `[{name, rates: [{rate, country, …}]}]`, dans l'ordre d'apparition.
     *
     * @param list<array> $rates
     */
    public static function nest(array $rates): array
    {
        $types = [];
        foreach ($rates as $r) {
            $types[$r['key']] ??= ['name' => $r['name'], 'rates' => []];
            $types[$r['key']]['rates'][] = [
                'rate' => $r['bp'] / 100, 'country' => $r['country'], 'region' => $r['region'], 'postcodes' => implode(';', $r['postcodes']),
                'label' => $r['label'], 'priority' => $r['priority'], 'compound' => $r['compound'], 'shipping' => $r['shipping'],
                'category' => $r['category'], 'mention' => $r['mention'],
            ];
        }

        return array_values($types);
    }

    /**
     * @return array{enabled: bool, prices_entered: string, based_on: string, shipping_tax: string, shop: array{country: string, region: string, postcode: string},
     *               default_rate: string, small_business: array{enabled: bool, mention: string}, names: array<string, string>,
     *               rates: list<array>, types: array<string, string>, aliases: array<string, string>}
     *         Clés de taux (`default_rate`, `types`, `shipping_tax` fixe, `rates[].key`) ; `names` : clé → nom affiché.
     */
    public static function fromConfig(array $config): array
    {
        $t = is_array($config['taxes'] ?? null) ? $config['taxes'] : [];
        $aliases = [];

        // Ancien format à classes : code → libellé.
        $classLabels = [];
        foreach ((array) ($t['classes'] ?? []) as $code => $label) {
            if (is_array($label)) {   // liste Admin2 : [{code, label}]
                [$code, $label] = [(string) ($label['code'] ?? ''), (string) ($label['label'] ?? '')];
            }
            if (trim((string) $code) !== '') {
                $classLabels[trim((string) $code)] = trim((string) $label) !== '' ? trim((string) $label) : trim((string) $code);
            }
        }

        $rawRates = $t['rates'] ?? null;
        $rows = [];
        if (is_array($t['tax_types'] ?? null) && $t['tax_types'] !== []) {
            // Format actuel : types de taxe, chacun avec ses taux.
            foreach ($t['tax_types'] as $type) {
                if (!is_array($type) || trim((string) ($type['name'] ?? '')) === '') {
                    continue;
                }
                foreach ((array) ($type['rates'] ?? []) as $rate) {
                    if (is_array($rate)) {
                        $rows[] = ['name' => trim((string) $type['name'])] + array_diff_key($rate, ['name' => 1, 'key' => 1, 'class' => 1]);
                    }
                }
            }
        } elseif (is_array($rawRates) && $rawRates !== [] && !array_is_list($rawRates)) {
            // Très ancien format : CODE: {label, rate}
            foreach ($rawRates as $code => $def) {
                if (!is_array($def) || !isset($def['rate']) || !is_numeric($def['rate'])) {
                    continue;
                }
                $rate = (float) $def['rate'];
                $rate = $rate <= 1 ? $rate * 100 : $rate;
                $name = (string) ($def['label'] ?? $code);
                $rows[] = ['name' => $name, 'country' => 'FR', 'rate' => $rate, 'label' => $name, 'category' => $rate > 0 ? 'S' : 'Z'];
                $aliases[self::key((string) $code)] = self::key($name);
            }
        } elseif (is_array($rawRates)) {
            foreach ($rawRates as $row) {
                if (is_array($row) && isset($row['class']) && !isset($row['key'])) {   // format à classes
                    $code = trim((string) $row['class']);
                    $name = $classLabels[$code] ?? self::LEGACY_NAMES[$code] ?? $code;
                    $row = ['name' => $name, 'label' => (string) ($row['name'] ?? '')] + $row;
                    unset($row['class']);
                    $aliases[self::key($code)] = self::key($name);
                }
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            $rows = self::DEFAULT_RATES;
        }

        $rates = [];
        $names = [];
        foreach ($rows as $row) {
            $n = self::normalizeRate($row);
            if ($n !== null) {
                $rates[] = $n;
                $names[$n['key']] ??= $n['name'];
            }
        }

        // Anciens codes de classe (`reduit`…) et « TVA_5_5 » (blueprints, commandes) : alias vers le nom du taux.
        foreach (self::LEGACY_NAMES as $code => $name) {
            if (isset($names[self::key($name)])) {
                $aliases[$code] ??= self::key($name);
            }
        }
        foreach ($rates as $r) {
            if ($r['country'] === 'FR' && $r['category'] !== 'E') {
                $legacy = self::key('TVA_' . str_replace('.', '_', rtrim(rtrim(number_format($r['bp'] / 100, 2, '.', ''), '0'), '.')));
                $aliases[$legacy] ??= $r['key'];
            }
        }
        foreach (array_keys($names) as $k) {
            unset($aliases[$k]);   // un vrai nom de taux l'emporte sur un alias
        }
        $resolve = static function (string $ref) use ($names, $aliases): string {
            $k = self::key($ref);
            $k = $aliases[$k] ?? $k;

            return isset($names[$k]) ? $k : '';
        };

        // Type de produit → type de taxe (surcharge du blueprint) : liste Admin2 `product_types` [{type, tax_type}]
        // (anciens : `types` [{type, rate}] ou [{type, class}]) ou map.
        $types = [];
        foreach ((array) ($t['product_types'] ?? $t['types'] ?? []) as $type => $ref) {
            if (is_array($ref)) {
                [$type, $ref] = [(string) ($ref['type'] ?? ''), (string) ($ref['tax_type'] ?? $ref['rate'] ?? $ref['class'] ?? '')];
            }
            $k = $resolve((string) $ref);
            if ((string) $type !== '' && $k !== '') {
                $types[(string) $type] = $k;
            }
        }

        $default = $resolve((string) ($t['default_type'] ?? $t['default_rate'] ?? $t['default_class'] ?? $t['default'] ?? ''));
        if ($default === '') {
            $default = $resolve(self::DEFAULT_RATE) ?: (string) array_key_first($names);
        }

        $shop = is_array($t['shop'] ?? null) ? $t['shop'] : [];
        $sb = is_array($t['small_business'] ?? null) ? $t['small_business'] : [];
        $basedOn = (string) ($t['based_on'] ?? 'shipping');
        $shipping = (string) ($t['shipping_tax'] ?? 'items');
        if (!in_array($shipping, self::SHIPPING_MODES, true)) {
            $shipping = $resolve($shipping) ?: 'items';
        }

        return [
            'enabled'        => !array_key_exists('enabled', $t) || !empty($t['enabled']),
            'prices_entered' => strtolower((string) ($t['prices_entered'] ?? 'ht')) === 'ttc' ? 'ttc' : 'ht',
            'based_on'       => in_array($basedOn, self::BASED_ON, true) ? $basedOn : 'shipping',
            'shipping_tax'   => $shipping,
            'shop'           => [
                'country'  => self::country((string) ($shop['country'] ?? $config['seller']['country'] ?? 'FR')) ?: 'FR',
                'region'   => strtoupper(trim((string) ($shop['region'] ?? ''))),
                'postcode' => trim((string) ($shop['postcode'] ?? $config['seller']['postal_code'] ?? '')),
            ],
            'default_rate'   => $default,
            'small_business' => [
                'enabled' => !empty($sb['enabled']),
                'mention' => trim((string) ($sb['mention'] ?? '')) ?: 'TVA non applicable, art. 293 B du CGI',
            ],
            'names'          => $names,
            'rates'          => $rates,
            'types'          => $types,
            'aliases'        => $aliases,
        ];
    }

    /**
     * Ligne de la table des taux normalisée, ou null si inutilisable. Accepte une ligne saisie (`name`, `rate` en %,
     * `label`…) ou déjà normalisée (`key`, `bp` : événement onSimpleShopTaxRates).
     */
    public static function normalizeRate($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        if (isset($row['class']) && !isset($row['key'])) {   // ancienne ligne {class, name = libellé}
            $row = ['key' => $row['class'], 'label' => $row['label'] ?? $row['name'] ?? ''] + array_diff_key($row, ['name' => 1]);
        }
        $name = trim((string) ($row['name'] ?? ''));
        $key = self::key((string) ($row['key'] ?? $name));
        // Taux en % (« 5.5 », « 5,5 ») ; une ligne déjà normalisée porte `bp`.
        $rate = isset($row['bp']) && !isset($row['rate']) ? (string) ((int) $row['bp'] / 100) : str_replace(',', '.', (string) ($row['rate'] ?? ''));
        if ($key === '' || !is_numeric($rate) || (float) $rate < 0 || (float) $rate >= 100) {
            return null;
        }
        $bp = (int) round((float) $rate * 100);
        $category = strtoupper(trim((string) ($row['category'] ?? '')));
        if (!in_array($category, \Grav\Plugin\SimpleShop\Pricing\TaxComponent::CATEGORIES, true)) {
            $category = $bp > 0 ? 'S' : 'Z';
        }
        $postcodes = $row['postcodes'] ?? $row['postcode'] ?? '';
        $postcodes = is_array($postcodes) ? $postcodes : preg_split('/[;,\s]+/', (string) $postcodes);
        $pct = rtrim(rtrim(number_format($bp / 100, 3, ',', ''), '0'), ',') . ' %';

        return [
            'key'       => $key,
            'name'      => $name !== '' ? $name : $key,
            'country'   => self::country((string) ($row['country'] ?? '')),
            'region'    => strtoupper(trim((string) ($row['region'] ?? $row['state'] ?? ''))),
            'postcodes' => array_values(array_filter(array_map('trim', $postcodes), 'strlen')),
            'bp'        => $bp,
            'label'     => trim((string) ($row['label'] ?? '')) ?: ($name !== '' ? $name . ' ' . $pct : $pct),
            'priority'  => max(1, (int) ($row['priority'] ?? 1)),
            'compound'  => !empty($row['compound']),
            'shipping'  => !array_key_exists('shipping', $row) || !empty($row['shipping']),
            'category'  => $category,
            'mention'   => trim((string) ($row['mention'] ?? '')),
        ];
    }

    /** Clé d'un nom de taux : minuscules sans accents, mots séparés par « - » (« Taux réduit » → « taux-reduit »). */
    public static function key(string $name): string
    {
        $s = trim($name);
        if ($s === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
                'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ç' => 'C']);
        }
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s));

        return substr(trim($s, '-'), 0, 60);
    }

    private static function country(string $c): string
    {
        $c = strtoupper(trim($c));

        return preg_match('/^[A-Z]{2}$/', $c) ? $c : '';
    }

    private static function siteConfig(): array
    {
        try {
            return (array) \Grav\Common\Grav::instance()['config']->get('plugins.simple-shop', []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Options des listes de l'administration (blueprint `data-options@`) : noms des types de taxe (valeur = nom). */
    public static function rateOptions(): array
    {
        $names = array_values(self::fromConfig(self::siteConfig())['names']);

        return array_combine($names, $names) ?: [];
    }

    /** Options : modes de taxe de la livraison (dont « toujours tel taux »). */
    public static function shippingOptions(): array
    {
        $opts = [
            'items'         => 'Selon les articles du panier (panier mixte : taux le plus élevé, comme WooCommerce)',
            'items_prorata' => 'Selon les articles du panier, réparti au prorata du HT (pratique française recommandée)',
        ];
        foreach (self::rateOptions() as $name) {
            $opts[$name] = 'Toujours le type « ' . $name . ' »';
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
