<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Contrat entre `simple-shop` et un plugin transporteur (voir CONCEPTION-TRANSPORTEURS.md §4).
 *
 * Le plugin s'enregistre en écoutant l'événement `onSimpleShopRegisterCarriers` :
 *
 *     public function onSimpleShopRegisterCarriers(Event $event): void {
 *         $carriers = $event['carriers'];            // tableau passé par référence
 *         $carriers['colissimo'] = new ColissimoProvider(...);
 *         $event['carriers'] = $carriers;
 *     }
 *
 * (abonnement STATIQUE dans getSubscribedEvents() et autoloader PSR-4 dans le constructeur du plugin,
 * comme pour les passerelles de paiement).
 *
 * Le fournisseur DÉCRIT ses modes (valeurs par défaut) et sait chercher des points de retrait. Le cœur
 * reste propriétaire de tout le reste : marge, offre « gratuit dès X € », TVA, plancher légal, arrondi,
 * réglages modifiés dans l'administration (ils se superposent aux valeurs par défaut du fournisseur).
 */
interface CarrierProviderInterface
{
    /** Version du contrat ; le cœur ignore un fournisseur d'une autre version. */
    public const API_VERSION = 1;

    /** Identifiant court et stable, en minuscules (`colissimo`). Préfixe des clés de modes : `colissimo.domicile`. */
    public function getId(): string;

    /** Nom affiché dans l'administration. */
    public function getLabel(): string;

    /**
     * Modes proposés, indexés par clé locale (sans le préfixe `<id>.`), au format d'une entrée de `carriers.yaml` :
     * name, description, enabled, legal_floor, margin{fixed,percent}, free_from, constraints{max_weight,max_thickness},
     * rules[] (zone, min/max_basket, min/max_weight, max_thickness, price), plus :
     *   - requires_pickup_point (bool) : le client doit choisir un point de retrait ;
     *   - available (bool) : faux tant que le mode ne peut pas fonctionner (clés d'API absentes…) ;
     *   - unavailable_reason (string, facultatif) : explication affichée dans l'administration.
     * Les prix des rules sont dans la base choisie par `delivery.prices_entered` (HT par défaut).
     *
     * @return array<string, array>
     */
    public function getModes(): array;

    /**
     * Points de retrait proches de l'adresse, triés du plus proche au plus éloigné.
     *
     * @param string $modeKey Clé locale du mode.
     * @param array  $address Adresse normalisée (`postal-code`, `city`, `street`, `country`).
     * @param array  $metrics `weight` (g), `thickness` (mm), `subtotal` (€).
     *
     * @return array[] Points normalisés : id, name, street, postal-code, city, distance_m, hours (jour => texte),
     *                 network, type, latitude, longitude, max_weight_g, accessible.
     *
     * @throws CarrierUnavailable Si le service est indisponible ou refuse la demande.
     */
    public function findPickupPoints(string $modeKey, array $address, array $metrics): array;

    /**
     * Contrôle de connexion déclenché depuis l'administration (bouton « Tester »).
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
