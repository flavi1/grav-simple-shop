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
    public static function all(array $config): array
    {
        $r = $config['routes'] ?? [];
        return [
            'cart'                    => $r['cart'] ?? '/cart',
            'validation'              => $r['validation'] ?? '/checkout/email',
            'delivery'                => $r['delivery'] ?? '/checkout/delivery',
            'payment'                 => $r['payment'] ?? '/checkout/payment',
            'confirmation'            => $r['confirmation'] ?? '/checkout/confirmation',
            'payment_return'          => $r['payment_return'] ?? '/checkout/payment-return',
            'identify'                => $r['identify'] ?? '/identify',
            'account'                 => $r['account'] ?? '/mon-compte',
            'newsletter_unsubscribe'  => $r['newsletter_unsubscribe'] ?? '/newsletter/unsubscribe',
        ];
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
        $all = self::all($config);
        return $all[$key] ?? $fallback;
    }
}
