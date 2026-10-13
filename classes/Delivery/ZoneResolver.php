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

            // Départements inexistants (00, 96, 99…) : pas de zone française.
            if ($dept === '' || (int) $dept < 1 || (int) $dept > 95) {
                return 'INTERNATIONAL';
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

    /** Zones desservies par défaut : France métropolitaine hors Corse. */
    public const DEFAULT_ALLOWED_ZONES = ['FR-METRO'];

    /** Message par défaut quand la destination n'est pas desservie. */
    public const DEFAULT_UNSUPPORTED_MESSAGE =
        'Nous livrons uniquement en France métropolitaine, hors Corse. '
        . 'La Corse, l’outre-mer et l’étranger ne sont pas desservis : '
        . 'contactez-nous pour une commande particulière.';

    /**
     * Zones desservies par la boutique (`plugins.simple-shop.delivery.allowed_zones`).
     *
     * Le plugin sait résoudre toutes les destinations (FR-METRO, FR-CORSE,
     * FR-OM, EU-ZONE-1, INTERNATIONAL) ; c'est cette liste qui restreint ce
     * que CE site accepte. Une liste vide ou invalide retombe sur le défaut.
     *
     * @param array $config Configuration du plugin simple-shop.
     *
     * @return string[]
     */
    public static function allowedZones(array $config): array
    {
        $zones = $config['delivery']['allowed_zones'] ?? null;
        if (!is_array($zones) || $zones === []) {
            return self::DEFAULT_ALLOWED_ZONES;
        }

        return array_values(array_filter(array_map('strval', $zones), static fn ($z) => $z !== ''));
    }

    /**
     * Vrai si l'adresse du client est dans une zone desservie.
     *
     * En France, le code postal doit de plus avoir cinq chiffres. Pour les
     * autres pays, seule la zone compte (le format des codes postaux varie).
     *
     * @param array $customerData Client (`country`, `postal-code`).
     * @param array $config       Configuration du plugin simple-shop.
     */
    public static function isDeliverable(array $customerData, array $config): bool
    {
        $country = strtoupper(trim((string) ($customerData['country'] ?? 'FR')));
        $zip = trim((string) ($customerData['postal-code'] ?? ''));

        if (($country === 'FR' || $country === 'FRANCE') && !preg_match('/^\d{5}$/', $zip)) {
            return false;
        }

        return in_array(self::resolve($customerData), self::allowedZones($config), true);
    }

    /** Message affiché au client dont la destination n'est pas desservie. */
    public static function unsupportedMessage(array $config): string
    {
        $message = trim((string) ($config['delivery']['unsupported_message'] ?? ''));

        return $message !== '' ? $message : self::DEFAULT_UNSUPPORTED_MESSAGE;
    }
}
