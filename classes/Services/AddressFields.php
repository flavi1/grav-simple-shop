<?php

namespace Grav\Plugin\SimpleShop\Services;

/**
 * Vocabulaire des champs d'adresse client et règle de livraison.
 *
 * Noms de champs (formulaires, session, profil persisté, commandes) :
 *
 *  - `street`             : numéro et nom de la voie (obligatoire) ;
 *  - `address-complement` : bâtiment, étage, lieu-dit… (OPTIONNEL) ;
 *  - `postal-code`        : code postal (obligatoire) ;
 *  - `city`               : ville (obligatoire).
 *
 * Anciens noms (`address-line1`, `address-line2` = ville, `address-line3` =
 * complément) : encore présents dans les profils déjà enregistrés et dans les
 * commandes passées. {@see self::normalize()} les convertit à la lecture ; plus
 * aucun code n'en écrit de nouveaux.
 *
 * Les clés du VENDEUR (`seller.address_line1`, `seller.postal_code`…) de la
 * configuration du plugin ne sont pas concernées : elles décrivent l'adresse
 * de la maison d'édition, pas celle d'un client.
 */
final class AddressFields
{
    /** Correspondance ancien nom -> nouveau nom. */
    public const LEGACY_FIELDS = [
        'address-line1' => 'street',
        'address-line2' => 'city',
        'address-line3' => 'address-complement',
    ];

    /** Message affiché à tout client hors zone de livraison. */
    public const UNSUPPORTED_ZONE_MESSAGE =
        'Nous livrons uniquement en France métropolitaine, hors Corse. '
        . 'La Corse, l’outre-mer et l’étranger ne sont pas desservis : '
        . 'contactez-nous pour une commande particulière.';

    /**
     * Convertit les anciens noms de champs vers les nouveaux (sans écraser une
     * valeur déjà présente sous le nouveau nom).
     *
     * @param array<string,mixed> $data Données client / facturation.
     *
     * @return array<string,mixed>
     */
    public static function normalize(array $data): array
    {
        foreach (self::LEGACY_FIELDS as $old => $new) {
            if (array_key_exists($old, $data)) {
                if (!array_key_exists($new, $data) || $data[$new] === '' || $data[$new] === null) {
                    $data[$new] = $data[$old];
                }
                unset($data[$old]);
            }
        }

        return $data;
    }

    /**
     * Vrai si le code postal désigne la France métropolitaine hors Corse :
     * cinq chiffres, départements 01 à 95 sauf 20 (Corse). Les 97x/98x
     * (outre-mer) et 99 (étranger) sont refusés.
     */
    public static function isDeliverablePostalCode(string $postalCode): bool
    {
        $postalCode = trim($postalCode);

        if (!preg_match('/^\d{5}$/', $postalCode)) {
            return false;
        }

        $dept = (int) substr($postalCode, 0, 2);

        return $dept >= 1 && $dept <= 95 && $dept !== 20;
    }
}
