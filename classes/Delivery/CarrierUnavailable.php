<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Le service d'un transporteur est momentanément inutilisable (réseau, identifiants refusés, quota…).
 * Le message est destiné au journal, jamais affiché tel quel au client.
 */
class CarrierUnavailable extends \RuntimeException
{
}
