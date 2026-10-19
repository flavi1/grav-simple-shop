<?php

namespace Grav\Plugin\SimpleShop\Gateways;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

/**
 * Passerelles de paiement enregistrées (événement `onSimpleShopRegisterGateways`), filtrées par usage :
 *  - **boutique** (tunnel d'achat, {@see PaymentGatewayInterface}) : `payments.methods.shop` ;
 *  - **documents** (factures et demandes de paiement de l'administration, {@see DocumentPaymentGatewayInterface}) :
 *    `payments.methods.manual`, puis les moyens retenus pour le document.
 * Liste de configuration vide = toutes les passerelles qui le permettent ; l'ordre de la liste est celui de l'affichage.
 */
final class GatewayRegistry
{
    /** @return array<string, object> Toutes les passerelles, par identifiant. */
    public static function all(): array
    {
        $gateways = [];
        Grav::instance()->fireEvent('onSimpleShopRegisterGateways', new Event(['gateways' => &$gateways]));

        return is_array($gateways) ? $gateways : [];
    }

    /** @return array<string, PaymentGatewayInterface> */
    public static function forShop(): array
    {
        $all = array_filter(self::all(), static fn ($g) => $g instanceof PaymentGatewayInterface);

        return self::filter($all, (array) Grav::instance()['config']->get('plugins.simple-shop.payments.methods.shop', []));
    }

    /**
     * @param list<string>|null $allowed Moyens retenus pour un document (null = ceux de la configuration).
     *
     * @return array<string, DocumentPaymentGatewayInterface>
     */
    public static function forDocuments(?array $allowed = null): array
    {
        $all = array_filter(self::all(), static fn ($g) => $g instanceof DocumentPaymentGatewayInterface);
        $list = self::filter($all, (array) Grav::instance()['config']->get('plugins.simple-shop.payments.methods.manual', []));

        return $allowed === null ? $list : self::filter($list, $allowed);
    }

    /** @return array<string, string> Identifiant → libellé des moyens utilisables pour un document. */
    public static function documentChoices(): array
    {
        return array_map(static fn ($g) => $g->getLabel(), self::forDocuments());
    }

    private static function filter(array $gateways, array $wanted): array
    {
        $wanted = array_values(array_filter(array_map('strval', $wanted), static fn ($id) => $id !== ''));
        if ($wanted === []) {
            return $gateways;
        }
        $out = [];
        foreach ($wanted as $id) {
            if (isset($gateways[$id])) {
                $out[$id] = $gateways[$id];
            }
        }

        return $out;
    }
}
