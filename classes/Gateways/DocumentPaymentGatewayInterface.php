<?php

namespace Grav\Plugin\SimpleShop\Gateways;

/**
 * Passerelle capable d'encaisser un document (facture, acompte, demande de paiement) envoyé depuis l'administration.
 * Indépendant du tunnel d'achat ({@see PaymentGatewayInterface}) : une passerelle peut implémenter les deux contrats.
 *
 * Une passerelle **hors ligne** (virement) n'encaisse rien sur le site : elle affiche des instructions et le vendeur
 * enregistre le paiement à réception (Admin2 > Ventes).
 */
interface DocumentPaymentGatewayInterface
{
    /** Identifiant unique (ex. 'stripe', 'bank_transfer'). */
    public function getId(): string;

    /** Libellé affiché au client (ex. « Carte bancaire »). */
    public function getLabel(): string;

    /** Vrai pour un moyen hors ligne (instructions seulement, pas de processDocumentPayment()). */
    public function isOffline(): bool;

    /**
     * Bloc HTML affiché sur la page du document quand le client choisit ce moyen : formulaire (POST sur
     * `$ctx->pageUrl`, `action=execute_document_payment`, `nonce`) ou instructions.
     */
    public function renderDocumentPayment(PaymentContext $ctx): string;

    /**
     * Valide le paiement après soumission du formulaire. Le montant encaissé est TOUJOURS vérifié auprès de la passerelle.
     *
     * @return array{success: bool, error: ?string, payment_reference?: ?string, amount_cents?: int}
     */
    public function processDocumentPayment(PaymentContext $ctx, array $postData): array;
}
