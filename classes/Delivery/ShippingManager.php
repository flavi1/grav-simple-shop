<?php
// classes/Delivery/ShippingManager.php

namespace Grav\Plugin\SimpleShop\Delivery;

use Grav\Common\Grav;
use Grav\Common\Data\Data;

class ShippingManager
{
    /** @var array<string, array> Entrées de carriers.yaml (modes manuels et surcharges de modes de fournisseurs). */
    protected $carriersConfig = [];

    /** @var CarrierProviderInterface[] */
    protected $providers = [];

    /** @var array<string, array>|null Cache des modes assemblés. */
    private $modes = null;

    /** @var array[] Contraintes globales (ShippingConstraints), dans l'ordre d'application. */
    protected $constraints = [];

    /** Vrai si carriers.yaml contient un bloc `constraints`. */
    protected $constraintsStored = false;

    public function __construct()
    {
        $grav = Grav::instance();
        // Chemin exact vers le fichier de configuration personnalisé
        $filePath = $grav['locator']->findResource('user://config/plugins/simple-shop/carriers.yaml');

        if ($filePath && file_exists($filePath)) {
            $data = json_decode( json_encode( \Symfony\Component\Yaml\Yaml::parseFile($filePath) ), true);
            $this->carriersConfig = $data['carriers'] ?? [];
            $this->constraintsStored = is_array($data) && array_key_exists('constraints', $data);
            if ($this->constraintsStored) {
                $this->constraints = ShippingConstraints::normalize($data['constraints']);
            }
        }

        // Fournisseurs (plugins transporteur), voir CarrierProviderInterface.
        $providers = [];
        $grav->fireEvent('onSimpleShopRegisterCarriers', new \RocketTheme\Toolbox\Event\Event(['carriers' => &$providers]));
        $this->providers = array_filter((array) $providers, static fn ($p) => $p instanceof CarrierProviderInterface);
    }

    /** @return CarrierProviderInterface[] Fournisseurs enregistrés (id => objet). */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /** @return array[] Contraintes globales normalisées, dans l'ordre d'application. */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /** carriers.yaml contient-il un bloc `constraints` ? */
    public function constraintsStored(): bool
    {
        return $this->constraintsStored;
    }

    /**
     * Type d'un produit pour les contraintes globales : `product_type` de la fiche, sinon nom de son gabarit (ex. book).
     */
    public static function productType(array $header, string $template): string
    {
        $type = trim((string) ($header['product_type'] ?? ''));

        return $type !== '' ? $type : $template;
    }

    /** Tous les modes (manuels et de fournisseurs), actifs ou non, pour l'administration. @return array<string, array> */
    public function getModes(): array
    {
        return $this->modes ??= CarrierCatalog::build($this->providers, $this->carriersConfig);
    }

    /**
     * Calcule le poids (g), l'épaisseur max (mm) et le montant total des articles du panier
     */
    public function getCartPhysicalMetrics(array $cartItems): array
    {
        $grav = Grav::instance();
        $pages = $grav['pages'];
        $pages->init();

        $totalWeight = 0;
        $stackThickness = 0;
        $basketSubtotal = 0;
        $subtotals = [];
        $packaging = self::packaging((array) $grav['config']->get('plugins.simple-shop', []));

		foreach ($cartItems as $route => $item) {
			$basketSubtotal += ($item['price'] * $item['quantity']);
			$type = '';

			// Recherche de la page produit Grav
			$page = $pages->dispatch($route);
			
			if ($page) {
				$header = (array)$page->header();
				$type = self::productType($header, (string) $page->template());
				
				// Si les données sont à la racine (comme dans book.yaml) 
				// ou imbriquées dans un sous-tableau 'shop'
				$shopMeta = $header['shop'] ?? $header;

				// Extraction du poids (conversion cm/g ou fallback à 100g)
				$weight = (int)($shopMeta['weight'] ?? 100);
				$totalWeight += ($weight * $item['quantity']);

				// Extraction de l'épaisseur
				// Note : Votre blueprint stocke l'épaisseur en cm (ex: 2.3).
				// Si votre grille de transporteur attend des mm, pensez à multiplier par 10 :
				$thickness = (float)($shopMeta['thickness'] ?? 0);
				// Les exemplaires sont empilés : l'épaisseur s'additionne.
				$stackThickness += (int) round($thickness * 10) * (int) $item['quantity'];
			} else {
				// Fallback si la page produit physique n'est pas résolue
				$totalWeight += (100 * $item['quantity']);
			}
			$subtotals[$type] = ($subtotals[$type] ?? 0) + $item['price'] * $item['quantity'];
		}

        // Emballage : poids fixe et surépaisseur par commande (valeurs de départ, réglables).
        if ($cartItems !== []) {
            $totalWeight += $packaging['weight'];
            $stackThickness += $packaging['thickness'];
        }

        return [
            'subtotal'  => $basketSubtotal,
            'subtotals' => $subtotals,
            'weight'    => $totalWeight,
            'thickness' => $stackThickness
        ];
    }

    /**
     * Emballage ajouté à chaque commande avant de choisir un transporteur
     * (`delivery.packaging.weight` en g, `delivery.packaging.thickness` en mm).
     *
     * @return array{weight: int, thickness: int}
     */
    public static function packaging(array $config): array
    {
        $raw = (array) ($config['delivery']['packaging'] ?? []);

        return [
            'weight'    => isset($raw['weight']) && is_numeric($raw['weight']) && $raw['weight'] >= 0 ? (int) round($raw['weight']) : 40,
            'thickness' => isset($raw['thickness']) && is_numeric($raw['thickness']) && $raw['thickness'] >= 0 ? (int) round($raw['thickness']) : 6,
        ];
    }

    /** Les prix de `carriers.yaml` sont-ils saisis hors taxe ? (`delivery.prices_entered`, `ht` par défaut) */
    public static function pricesEnteredHt(array $config): bool
    {
        return strtolower((string) ($config['delivery']['prices_entered'] ?? 'ht')) !== 'ttc';
    }

    /**
     * Prix de livraison TTC d'un palier : prix de la grille + marge (fixe et/ou en %), puis TVA du site si les prix
     * sont saisis HT. Un palier à 0 (livraison offerte) reste à 0 : la marge ne s'y ajoute pas.
     * Les contraintes globales (ShippingConstraints) s'appliquent ensuite, sur le TTC.
     */
    public static function priceTtc(float $gridPrice, array $margin, array $config): float
    {
        $price = $gridPrice;
        if ($price > 0) {
            $price = $price * (1 + max(0.0, (float) ($margin['percent'] ?? 0)) / 100) + max(0.0, (float) ($margin['fixed'] ?? 0));
        }
        if (self::pricesEnteredHt($config)) {
            $price = $price * (1 + \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate($config));
        }

        return round($price, 2);
    }

    /**
     * Prix d'un mode pour un panier, avec le détail du calcul (utilisé aussi par le simulateur d'administration).
     *
     * @param array $mode    Mode normalisé (CarrierCatalog).
     * @param array $metrics     `subtotal` (€ TTC, tous articles), `subtotals` (€ TTC par type), `weight` (g), `thickness` (mm).
     * @param array $constraints Contraintes globales normalisées (ShippingConstraints).
     *
     * @return array|null null = mode inéligible (désactivé, indisponible, hors contraintes, aucun palier) ;
     *         sinon price (TTC final), grid_price (base saisie), after_margin, vat_rate, price_before_constraints,
     *         constraints (contraintes appliquées), free_applied.
     */
    public static function quoteMode(array $mode, string $zone, array $metrics, array $config, bool $ignoreEnabled = false, array $constraints = []): ?array
    {
        return self::evaluate($mode, $zone, $metrics, $config, $ignoreEnabled, $constraints)['quote'];
    }

    /**
     * Comme quoteMode(), mais explique aussi pourquoi un mode n'est pas proposé (simulateur d'administration).
     *
     * @return array{quote: ?array, reason: string} reason vide si le mode est proposé.
     */
    public static function evaluate(array $mode, string $zone, array $metrics, array $config, bool $ignoreEnabled = false, array $constraints = []): array
    {
        $no = static fn (string $reason): array => ['quote' => null, 'reason' => $reason];

        if (!$ignoreEnabled && empty($mode['enabled'])) {
            return $no('Mode désactivé.');
        }
        if (empty($mode['available'])) {
            return $no((string) ($mode['unavailable_reason'] ?? '') ?: 'Mode indisponible.');
        }

        if (($mode['kind'] ?? 'delivery') === 'local_pickup') {
            return self::evaluateLocalPickup($mode, $metrics, $config, $constraints);
        }

        $limits = $mode['constraints'] ?? [];
        if (!empty($limits['max_weight']) && $metrics['weight'] > $limits['max_weight']) {
            return $no('Colis trop lourd pour ce mode (' . $limits['max_weight'] . ' g au plus).');
        }
        if (!empty($limits['max_thickness']) && $metrics['thickness'] > $limits['max_thickness']) {
            return $no('Colis trop épais pour ce mode (' . $limits['max_thickness'] . ' mm au plus).');
        }

        $flat = ($mode['pricing'] ?? 'grid') === 'flat';
        $matched = null;
        if ($flat) {
            if (!isset($mode['flat_price']) || !is_numeric($mode['flat_price'])) {
                return $no('Forfait sans prix.');
            }
            $matched = (float) $mode['flat_price'];
        }
        foreach ($flat ? [] : (array) ($mode['rules'] ?? []) as $rule) {
            if (($rule['zone'] ?? null) !== $zone) {
                continue;
            }
            if ($metrics['subtotal'] < ($rule['min_basket'] ?? 0) || $metrics['subtotal'] > ($rule['max_basket'] ?? 999999)) {
                continue;
            }
            if ($metrics['weight'] < ($rule['min_weight'] ?? 0) || $metrics['weight'] > ($rule['max_weight'] ?? 9999999)) {
                continue;
            }
            if (!empty($rule['max_thickness']) && $metrics['thickness'] > $rule['max_thickness']) {
                continue;
            }
            $matched = (float) $rule['price'];
            break; // première règle valide
        }
        if ($matched === null) {
            return $no('Aucune ligne de la grille ne correspond (zone, poids, épaisseur ou montant du panier).');
        }

        $freeApplied = !empty($mode['free_from']) && $metrics['subtotal'] >= $mode['free_from'];
        $grid = $freeApplied ? 0.0 : $matched;
        // Forfait : le prix saisi est le prix voulu, sans marge.
        $margin = $flat ? [] : (array) ($mode['margin'] ?? []);
        $before = self::priceTtc($grid, $margin, $config);

        // Contraintes globales (lois, offres commerciales), dans l'ordre de la liste.
        [$final, $applied] = ShippingConstraints::apply($before, (string) ($mode['key'] ?? ''), $metrics, $constraints);

        $quote = [
            'price'              => $final,
            'grid_price'         => $matched,
            'after_margin'       => $grid > 0
                ? round($grid * (1 + max(0.0, (float) ($margin['percent'] ?? 0)) / 100) + max(0.0, (float) ($margin['fixed'] ?? 0)), 4)
                : 0.0,
            'vat_rate'           => self::pricesEnteredHt($config) ? \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate($config) : 0.0,
            'price_before_constraints' => $before,
            'constraints'        => $applied,
            'free_applied'       => $freeApplied,
        ];

        return ['quote' => $quote, 'reason' => ''];
    }

    /**
     * Retrait sur place : proposé si le client (commune géocodée, `metrics.destination`) est à au plus `max_distance_km`
     * du lieu de retrait (`geo`, géocodé à l'enregistrement). Prix unique (`flat_price`, 0 par défaut), sans marge ni
     * contrainte de colis ; les contraintes globales s'appliquent sauf exclusion du mode.
     */
    private static function evaluateLocalPickup(array $mode, array $metrics, array $config, array $constraints): array
    {
        $no = static fn (string $reason): array => ['quote' => null, 'reason' => $reason];
        $geo = $mode['geo'] ?? null;
        $max = (float) ($mode['max_distance_km'] ?? 0);
        if ($geo === null || $max <= 0) {
            return $no('Lieu de retrait non localisé : enregistrez son adresse et la distance maximale.');
        }
        $dest = $metrics['destination'] ?? null;
        if (!is_array($dest) || !isset($dest['lat'], $dest['lon'])) {
            return $no('Adresse du client non localisée : distance inconnue.');
        }
        $km = Geocoder::distanceKm((float) $geo['lat'], (float) $geo['lon'], (float) $dest['lat'], (float) $dest['lon']);
        if ($km > $max) {
            return $no('Client à ' . number_format($km, 1, ',', ' ') . ' km du lieu de retrait (au plus ' . number_format($max, 1, ',', ' ') . ' km).');
        }

        $grid = max(0.0, (float) ($mode['flat_price'] ?? 0));
        $before = self::priceTtc($grid, [], $config);
        [$final, $applied] = ShippingConstraints::apply($before, (string) ($mode['key'] ?? ''), $metrics, $constraints);

        return ['quote' => [
            'price'                    => $final,
            'grid_price'               => $grid,
            'after_margin'             => $grid,
            'vat_rate'                 => self::pricesEnteredHt($config) ? \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate($config) : 0.0,
            'price_before_constraints' => $before,
            'constraints'              => $applied,
            'free_applied'             => false,
            'distance_km'              => round($km, 1),
        ], 'reason' => ''];
    }

    /** Le panier peut-il avoir besoin de la position du client (un retrait sur place actif et localisé) ? */
    public function needsDestination(): bool
    {
        foreach ($this->getModes() as $mode) {
            if ($mode['kind'] === 'local_pickup' && $mode['enabled'] && $mode['geo'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtre et retourne les modes éligibles avec leur tarif calculé (TTC).
     */
    public function getAvailableCarriers(string $zone, array $metrics): array
    {
        $available = [];
        $shopConfig = (array) Grav::instance()['config']->get('plugins.simple-shop', []);

        foreach ($this->getModes() as $key => $mode) {
            $quote = self::quoteMode($mode, $zone, $metrics, $shopConfig, false, $this->constraints);
            if ($quote === null) {
                continue;
            }

            $available[$key] = [
                'key'                   => $key,
                'name'                  => $mode['name'],
                'description'           => $mode['description'],
                'price'                 => $quote['price'],
                'provider'              => $mode['provider'],
                'requires_pickup_point' => $mode['requires_pickup_point'],
                'kind'                  => $mode['kind'],
                'distance_km'           => $quote['distance_km'] ?? null,
            ];
        }

        return $available;
    }

    /**
     * Points de retrait pour un mode de fournisseur.
     *
     * @return array[] Points normalisés (voir CarrierProviderInterface::findPickupPoints()).
     *
     * @throws CarrierUnavailable Si le mode n'existe pas ou si le service est indisponible.
     */
    public function findPickupPoints(string $modeKey, array $address, array $metrics): array
    {
        $mode = $this->getModes()[$modeKey] ?? null;
        if ($mode === null || $mode['provider'] === null || !$mode['requires_pickup_point'] || !isset($this->providers[$mode['provider']])) {
            throw new CarrierUnavailable("Mode sans point de retrait : {$modeKey}");
        }

        return $this->providers[$mode['provider']]->findPickupPoints($mode['local_key'], $address, $metrics);
    }
}
