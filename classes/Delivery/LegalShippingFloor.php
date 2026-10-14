<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * PLUS UTILISÉE (14/10/2026) : la loi Darcos est une contrainte globale de la configuration du site
 * (ShippingConstraints). Fichier à supprimer : voir REMOVE.md.
 *
 * Plancher légal des frais de livraison de livres (« loi Darcos »).
 *
 * Texte : loi n° 2021-1901 du 30 décembre 2021 (réforme de l'article 1er de la
 * loi du 10 août 1981 sur le prix du livre) et arrêté du 4 avril 2023, applicables
 * depuis le 7 octobre 2023. Pour une commande de livres neufs vendue à distance et
 * livrée, la livraison ne peut être gratuite :
 *   - commande inférieure à 35 € TTC : frais d'au moins 3,00 € TTC ;
 *   - à partir de 35 € TTC : frais d'au moins 0,01 € TTC.
 * Le retrait dans un commerce de détail de livres est exclu.
 *
 * Les montants sont des TTC, au taux de TVA du livre (5,5 %) y compris pour les
 * frais de port réglementés. Le seuil se compare au prix des livres de la
 * commande (hors frais de livraison) ; au seuil exact (35,00 €), le plancher
 * symbolique s'applique. Les valeurs se règlent dans
 * `plugins.simple-shop.delivery.book_law` (désactivable) afin de suivre une
 * évolution du texte sans toucher au code.
 *
 * À valider avec le Syndicat national de l'édition ou Légifrance avant la mise en
 * production (voir MANUAL_TESTS.md) : la base de calcul exacte du seuil et
 * l'application aux éditeurs vendant en direct ne sont pas détaillées par les
 * sources consultées.
 */
final class LegalShippingFloor
{
    public const DEFAULTS = [
        'enabled'     => true,
        'threshold'   => 35.00,
        'below'       => 3.00,
        'at_or_above' => 0.01,
    ];

    /**
     * Réglages effectifs (valeurs par défaut complétées par la configuration).
     *
     * @param array $config Configuration du plugin simple-shop.
     *
     * @return array{enabled: bool, threshold: float, below: float, at_or_above: float}
     */
    public static function settings(array $config): array
    {
        $raw = (array) ($config['delivery']['book_law'] ?? []);

        $number = static fn ($key) => isset($raw[$key]) && is_numeric($raw[$key]) && $raw[$key] >= 0
            ? (float) $raw[$key]
            : self::DEFAULTS[$key];

        return [
            'enabled'     => array_key_exists('enabled', $raw) ? filter_var($raw['enabled'], FILTER_VALIDATE_BOOLEAN) : self::DEFAULTS['enabled'],
            'threshold'   => $number('threshold'),
            'below'       => $number('below'),
            'at_or_above' => $number('at_or_above'),
        ];
    }

    /**
     * Frais minimaux (TTC) pour une commande dont les livres valent `$booksSubtotal` TTC.
     * Zéro si la règle est désactivée.
     */
    public static function minimum(float $booksSubtotal, array $config): float
    {
        $s = self::settings($config);
        if (!$s['enabled']) {
            return 0.0;
        }

        return $booksSubtotal < $s['threshold'] ? $s['below'] : $s['at_or_above'];
    }

    /** Applique le plancher à un tarif de livraison (TTC). */
    public static function apply(float $price, float $booksSubtotal, array $config): float
    {
        return round(max($price, self::minimum($booksSubtotal, $config)), 2);
    }
}
