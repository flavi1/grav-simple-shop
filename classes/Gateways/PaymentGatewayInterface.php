<?php
// user/plugins/simple-shop/classes/Gateways/PaymentGatewayInterface.php

namespace Grav\Plugin\SimpleShop\Gateways;

interface PaymentGatewayInterface
{
    /**
     * Retourne l'identifiant unique de la passerelle (ex: 'stripe', 'test_gateway')
     */
    public function getId(): string;

    /**
     * Retourne le nom affiché au client (ex: 'Carte Bancaire (Stripe)')
     */
    public function getLabel(): string;

    /**
     * Rend le fragment HTML nécessaire au Temps 2 (ex: conteneur Stripe Elements ou boutons Test)
     * 
     * @param array $orderData Toutes les informations de la commande en cours
     * @return string Code HTML / JS à injecter dans la page de paiement
     */
    public function renderPaymentForm(array $orderData): string;

    /**
     * Traite la capture finale ou la validation du paiement (Temps 3)
     * 
     * @param array $postData Le tableau $_POST reçu
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function processPayment(array $postData): array;
}
