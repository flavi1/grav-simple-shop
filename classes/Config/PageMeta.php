<?php

namespace Grav\Plugin\SimpleShop\Config;

/**
 * Titres et descriptions des pages de la boutique (tunnel, compte), appliqués à la Response du plugin mvc.
 *
 * Valeurs par défaut ci-dessous, remplaçables par site : `plugins.simple-shop.pages.<clé>.title` / `.description`.
 * Toutes ces pages sont propres à un visiteur (panier, compte…) : `robots: noindex, nofollow` (pas d'indexation).
 */
final class PageMeta
{
    public const DEFAULTS = [
        'cart'         => ['Votre panier', 'Votre panier : vérifiez vos articles et vos coordonnées avant de passer commande.'],
        'validation'   => ['Vérification de votre adresse e-mail', 'Saisissez le code reçu par e-mail pour confirmer votre adresse.'],
        'delivery'     => ['Livraison', 'Choisissez le mode de livraison de votre commande.'],
        'payment'      => ['Paiement', 'Adresse de facturation et paiement sécurisé de votre commande.'],
        'confirmation' => ['Confirmation de commande', 'Votre commande est enregistrée : récapitulatif et prochaines étapes.'],
        'identify'     => ['Connexion', 'Connectez-vous avec un code à usage unique envoyé par e-mail.'],
        'account'      => ['Mon compte', 'Vos coordonnées et vos préférences.'],
        'newsletter_unsubscribe' => ['Désabonnement', 'Désabonnement de la lettre d\'information.'],
        'document'     => ['Votre document', 'Consultation et règlement en ligne d\'une facture ou d\'une demande de paiement.'],
    ];

    /** Correspondance gabarit → clé (pour les gabarits du tunnel). */
    public const TEMPLATES = [
        'step1cart' => 'cart', 'email-validation' => 'validation', 'step2delivery' => 'delivery', 'step3payment' => 'payment',
        'step4confirmationorder' => 'confirmation', 'identify' => 'identify', 'account' => 'account',
        'newsletter-unsubscribe' => 'newsletter_unsubscribe', 'document' => 'document',
    ];

    /** Titre et description d'une page : configuration du site, sinon valeur par défaut. */
    public static function get(string $key, array $config = []): array
    {
        [$title, $description] = self::DEFAULTS[$key] ?? ['', ''];
        $over = (array) ($config['pages'][$key] ?? []);

        return [
            'title'       => trim((string) ($over['title'] ?? '')) ?: $title,
            'description' => trim((string) ($over['description'] ?? '')) ?: $description,
        ];
    }

    /**
     * Applique titre, description et `noindex` à une Response mvc. Sans effet si le plugin mvc installé est antérieur
     * à la 0.2.0 (méthodes absentes) : la page garde alors le titre déduit de sa route.
     *
     * @param \Grav\Plugin\Mvc\Response $response
     */
    public static function apply($response, string $keyOrTemplate, array $config = [])
    {
        $key = self::TEMPLATES[basename($keyOrTemplate)] ?? $keyOrTemplate;
        if (!isset(self::DEFAULTS[$key]) || !method_exists($response, 'setTitle')) {
            return $response;
        }
        $meta = self::get($key, $config);

        return $response->setTitle($meta['title'])->setDescription($meta['description'])->setMeta('robots', 'noindex, nofollow');
    }
}
