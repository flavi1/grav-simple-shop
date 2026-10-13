<?php
// classes/Delivery/ShippingManager.php

namespace Grav\Plugin\SimpleShop\Delivery;

use Grav\Common\Grav;
use Grav\Common\Data\Data;

class ShippingManager
{
    protected $carriersConfig = [];

    public function __construct()
    {
        $grav = Grav::instance();
        // Chemin exact vers le fichier de configuration personnalisé
        $filePath = Grav::instance()['locator']->findResource('user://config/plugins/simple-shop/carriers.yaml');
        
        if ($filePath && file_exists($filePath)) {
            $data = json_decode( json_encode( \Symfony\Component\Yaml\Yaml::parseFile($filePath) ), true);
            $this->carriersConfig = $data['carriers'] ?? [];
        }
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
        $maxThickness = 0;
        $basketSubtotal = 0;

		foreach ($cartItems as $route => $item) {
			$basketSubtotal += ($item['price'] * $item['quantity']);

			// Recherche de la page produit Grav
			$page = $pages->dispatch($route);
			
			if ($page) {
				$header = (array)$page->header();
				
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
				$thicknessInMm = (int)($thickness * 10); 
				
				if ($thicknessInMm > $maxThickness) {
					$maxThickness = $thicknessInMm;
				}
			} else {
				// Fallback si la page produit physique n'est pas résolue
				$totalWeight += (100 * $item['quantity']);
			}
		}

        return [
            'subtotal'  => $basketSubtotal,
            'weight'    => $totalWeight,
            'thickness' => $maxThickness
        ];
    }

    /**
     * Filtre et retourne les transporteurs éligibles avec leur tarif calculé
     */
    public function getAvailableCarriers(string $zone, array $metrics): array
    {
        $available = [];
        $shopConfig = (array) Grav::instance()['config']->get('plugins.simple-shop', []);

        foreach ($this->carriersConfig as $key => $carrier) {
            if (!($carrier['enabled'] ?? false)) {
                continue;
            }

            // 1. Vérification des contraintes globales du transporteur
            $constraints = $carrier['constraints'] ?? [];
            if (!empty($constraints['max_weight']) && $metrics['weight'] > $constraints['max_weight']) {
                continue;
            }
            if (!empty($constraints['max_thickness']) && $metrics['thickness'] > $constraints['max_thickness']) {
                continue;
            }

            // 2. Recherche d'une règle (palier) correspondante
            $matchedPrice = null;
            $rules = $carrier['rules'] ?? [];

            foreach ($rules as $rule) {
                if ($rule['zone'] !== $zone) {
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

                // Une règle correspond à tous les critères !
                $matchedPrice = (float)$rule['price'];
                break; // On prend la première règle valide trouvée
            }

            // Si un tarif a pu être calculé pour ce transporteur, on l'ajoute aux options
            if ($matchedPrice !== null) {
                // Plancher légal des frais de livraison de livres (loi Darcos).
                // `legal_floor: false` : retrait sur place, qui n'est pas une livraison au sens de la loi
                // (à n'utiliser que pour cela ; voir LegalShippingFloor).
                $price = ($carrier['legal_floor'] ?? true) === false
                    ? $matchedPrice
                    : LegalShippingFloor::apply($matchedPrice, (float) $metrics['subtotal'], $shopConfig);

                $available[$key] = [
                    'key'         => $key,
                    'name'        => $carrier['name'],
                    'description' => $carrier['description'],
                    'price'       => $price
                ];
            }
        }

        return $available;
    }
}
