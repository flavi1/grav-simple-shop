<?php

namespace Grav\Plugin\SimpleShop\Gateways;

/**
 * Passerelle capable de paiement avec REDIRECTION (page de paiement hébergée,
 * 3-D Secure, moyens de paiement locaux) : le client quitte le site puis y
 * revient sur la route `payment_return`.
 *
 * Ce contrat sert deux besoins :
 *  - le mode « sans JavaScript » : le client est envoyé vers la page de la
 *    passerelle par une simple redirection serveur ;
 *  - le mode JavaScript : quand la passerelle impose une redirection (3-D
 *    Secure), le retour passe par le même point d'entrée, qui vérifie le
 *    paiement auprès de la passerelle avant de créer la commande.
 */
interface RedirectPaymentGatewayInterface extends PaymentGatewayInterface
{
    /**
     * Démarre un paiement hébergé.
     *
     * @param array  $orderData Données de la commande en cours (session).
     * @param string $returnUrl URL absolue de retour (route `payment_return`) ;
     *                          la passerelle y ajoute `result=success|cancel`.
     *
     * @return array ['url' => ?string, 'error' => ?string] : URL vers laquelle
     *               rediriger le client, ou message d'erreur.
     */
    public function startHostedPayment(array $orderData, string $returnUrl): array;

    /**
     * Vérifie un retour de la passerelle (paramètres GET de la route de retour).
     *
     * La vérification se fait TOUJOURS auprès de la passerelle (jamais sur la
     * foi des paramètres d'URL), et le montant payé est comparé à celui de la
     * commande en cours.
     *
     * @param array $query     Paramètres GET reçus.
     * @param array $orderData Données de la commande en cours (session).
     *
     * @return array ['success' => bool, 'cancelled' => bool, 'error' => ?string,
     *               'payment_reference' => ?string]
     */
    public function confirmReturn(array $query, array $orderData): array;
}
