<?php

namespace Grav\Plugin\SimpleShop\Gateways;

/** Paiement de document avec redirection (page hébergée, 3-D Secure) : le client revient sur `$ctx->returnUrl`. */
interface RedirectDocumentPaymentGatewayInterface extends DocumentPaymentGatewayInterface
{
    /** @return array{url: ?string, error: ?string} */
    public function startHostedDocumentPayment(PaymentContext $ctx): array;

    /**
     * Vérifie un retour (paramètres GET) auprès de la passerelle : statut ET montant.
     *
     * @return array{success: bool, cancelled: bool, error: ?string, payment_reference?: ?string, amount_cents?: int}
     */
    public function confirmDocumentReturn(PaymentContext $ctx, array $query): array;
}
