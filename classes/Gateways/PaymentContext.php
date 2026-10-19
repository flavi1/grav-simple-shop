<?php

namespace Grav\Plugin\SimpleShop\Gateways;

/**
 * Ce qu'une passerelle doit savoir pour encaisser un **document** (facture, acompte, demande de paiement) envoyé depuis
 * l'administration : pas de panier ni de session de commande, le client arrive par le lien de l'e-mail
 * (`/document/<jeton>`).
 *
 * Les formulaires d'une passerelle postent sur `$pageUrl` avec `action=execute_document_payment` et un jeton anti-rejeu
 * `nonce` (action {@see self::NONCE_ACTION}) ; les paiements à redirection reviennent sur `$returnUrl`.
 */
final class PaymentContext
{
    public const NONCE_ACTION = 'shop-document-payment';

    public function __construct(
        public readonly int $documentId,
        public readonly string $documentNumber,
        public readonly string $documentKind,
        public readonly string $orderReference,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $customerEmail,
        public readonly string $description,
        public readonly string $pageUrl,
        public readonly string $returnUrl,
        public readonly string $token,
    ) {
    }

    /** Montant décimal pour l'affichage (« 120,00 »). */
    public function amountText(): string
    {
        return number_format($this->amountCents / 100, 2, ',', "\u{202F}");
    }

    /** Métadonnées à confier à une passerelle (ex. Stripe) pour retrouver le document depuis un webhook. */
    public function metadata(): array
    {
        return [
            'shop_document'       => $this->documentNumber,
            'shop_document_token' => $this->token,
            'shop_order'          => $this->orderReference,
            'customer_email'      => $this->customerEmail,
        ];
    }
}
