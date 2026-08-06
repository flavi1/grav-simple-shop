<?php
// classes/Delivery/ZoneResolver.php

namespace Grav\Plugin\SimpleShop\Delivery;

class ZoneResolver
{
    /**
     * Détermine la zone géographique d'un client à partir de ses informations
     */
    public static function resolve(array $customerData): string
    {
        $country = strtoupper(trim($customerData['country'] ?? 'FR'));
        $zip = trim($customerData['postal-code'] ?? '');

        if ($country === 'FR' || $country === 'FRANCE') {
            // Extraction des deux premiers caractères pour la France métropolitaine, Corse et DOM-TOM
            $dept = substr($zip, 0, 2);

            // Gestion de la Corse (2A, 2B) ou des codes postaux commençant par 20
            if ($dept === '20' || strtoupper($dept) === '2A' || strtoupper($dept) === '2B') {
                return 'FR-CORSE';
            }

            // Gestion simplifiée des DOM-TOM (commencent généralement par 97 ou 98)
            if ($dept === '97' || $dept === '98') {
                return 'FR-OM';
            }

            return 'FR-METRO';
        }

        // Liste simplifiée de pays de l'Union Européenne (Zone 1 dans l'exemple carriers.yaml)
        $euZone1 = ['BE', 'LU', 'NL', 'DE', 'IT', 'ES', 'AT'];
        if (in_array($country, $euZone1)) {
            return 'EU-ZONE-1';
        }

        return 'INTERNATIONAL';
    }
}
