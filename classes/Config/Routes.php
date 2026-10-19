<?php

namespace Grav\Plugin\SimpleShop\Config;

class Routes
{
    /**
     * Retourne la table complète des routes avec leurs valeurs par défaut.
     *
     * @param array $config Configuration du plugin simple-shop.
     * @return array
     */
    /** Routes par défaut (seule source des valeurs en dur). */
    public const DEFAULTS = [
        'cart'                    => '/cart',
        'validation'              => '/checkout/email',
        'delivery'                => '/checkout/delivery',
        'payment'                 => '/checkout/payment',
        'confirmation'            => '/checkout/confirmation',
        'payment_return'          => '/checkout/payment-return',
        'identify'                => '/identify',
        'account'                 => '/mon-compte',
        'logout'                  => '/mon-compte/deconnexion',
        'newsletter_unsubscribe'  => '/newsletter/unsubscribe',
        'document'                => '/document',   // page d'un document envoyé depuis l'administration : /document?t=<jeton>
    ];

    public static function all(array $config): array
    {
        $r = (array) ($config['routes'] ?? []);
        $all = [];
        foreach (self::DEFAULTS as $key => $default) {
            $all[$key] = isset($r[$key]) && is_string($r[$key]) && $r[$key] !== '' ? $r[$key] : $default;
        }

        return $all;
    }

    /**
     * Récupère une route spécifique avec un repli.
     *
     * @param array  $config   Configuration du plugin simple-shop.
     * @param string $key      Clé de la route.
     * @param string $fallback Chemin de repli.
     * @return string
     */
    public static function get(array $config, string $key, string $fallback = '/'): string
    {
        $route = self::all($config)[$key] ?? $fallback;

        return is_string($route) && $route !== '' ? $route : (self::DEFAULTS[$key] ?? $fallback);
    }
}
