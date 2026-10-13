<?php

namespace Grav\Plugin\SimpleShop\Services;

/**
 * Vocabulaire des champs d'adresse client (la règle de livraison est dans ZoneResolver).
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
}
