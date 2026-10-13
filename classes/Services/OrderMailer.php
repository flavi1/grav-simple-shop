<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Invoicing\InvoiceManager;
use Symfony\Component\Mime\Address;

/**
 * Service d'envoi des e-mails liés à une commande finalisée :
 *  - confirmation de commande au CLIENT ({@see self::sendOrderConfirmation()}) ;
 *  - notification de nouvelle commande au VENDEUR ({@see self::sendSellerNotification()}).
 *
 * Appelé par `CartController::step3Payment()` juste après
 * `CartStorage::toOrder()`. Volontairement sans AUCUNE dépendance à la base
 * de données pour le CONTENU de la commande : les données
 * (`$orderData`/`$orderItems`, telles que retournées par les tables
 * `order`/`order_item`) doivent être chargées par l'appelant et fournies
 * déjà prêtes, afin de garder cette classe focalisée sur le rendu/envoi des
 * e-mails. Seule exception : la génération/récupération du PDF Factur-X
 * (voir {@see self::resolveInvoicePdfPath()}), qui s'appuie directement sur
 * `Invoicing\InvoiceManager` (lequel gère lui-même l'accès à la table
 * `invoice`).
 *
 * S'appuie sur le service 'Email' de Grav (plugin Email officiel) et sur
 * `$this->grav['twig']->processTemplate()` pour rendre les templates HTML
 * dédiés : `templates/email/order-confirmation.html.twig` et
 * `templates/email/order-seller-notify.html.twig`.
 *
 * PIÈCE JOINTE FACTURE (voir CDC-E-REPORTING.md et NOTES-TODO.md) :
 * conformément au CDC (« le client reçoit sa facture par mail dès
 * validation du paiement, il ne la télécharge jamais lui-même »), le PDF
 * Factur-X de la commande est généré (à la demande, avec mise en cache
 * disque, voir `InvoiceManager::ensurePdfGenerated()`) et joint à l'e-mail
 * de confirmation CLIENT UNIQUEMENT ({@see self::sendOrderConfirmation()}).
 * ⚠️ Décision de conception assumée : la notification VENDEUR
 * ({@see self::sendSellerNotification()}) n'embarque PAS cette pièce jointe,
 * le vendeur disposant déjà d'un accès direct au téléchargement depuis
 * `/admin/simple-shop-orders` (voir `SimpleShopPlugin::onPagesInitialized()`,
 * location `simple-shop-invoice-download`) — à reconsidérer si un besoin de
 * réception systématique par e-mail est exprimé côté vendeur.
 *
 * La génération de facture est TOUJOURS non bloquante : tout échec (erreur
 * de génération PDF, service Email indisponible...) n'empêche jamais
 * l'envoi de l'e-mail de confirmation (sans pièce jointe dans ce cas) ni,
 * a fortiori, la finalisation de la commande (déjà persistée à ce stade).
 * Les échecs de génération sont journalisés dans `logs/simple-shop/invoices.log`
 * (voir {@see \Grav\Plugin\SimpleShop\Invoicing\InvoiceLogger}, appelée en
 * interne par `InvoiceManager::ensurePdfGenerated()`).
 *
 * @package Grav\Plugin\SimpleShop\Services
 */
class OrderMailer
{
    /**
     * Conteneur Grav.
     *
     * @var Grav
     */
    private Grav $grav;

    /**
     * Constructeur.
     *
     * @param Grav $grav Conteneur de services Grav.
     */
    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Envoie l'e-mail de confirmation de commande au client, avec la
     * facture Factur-X jointe en pièce jointe PDF (voir doc de classe).
     *
     * L'adresse de destination est lue depuis `$orderData['customer_data']`
     * (JSON), champ `email`.
     *
     * @param array $orderData  Ligne de la table `order` (voir schéma `shop.sql`).
     * @param array $orderItems Lignes de la table `order_item` associées.
     *
     * @return bool Vrai si l'e-mail a été accepté pour envoi.
     */
    public function sendOrderConfirmation(array $orderData, array $orderItems): bool
    {
        $customer = AddressFields::normalize(json_decode((string) ($orderData['customer_data'] ?? '{}'), true) ?: []);
        $email = trim((string) ($customer['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log('error', "Confirmation de commande annulée : adresse e-mail client invalide/absente "
                . "(commande '" . ($orderData['order_reference'] ?? '?') . "').");

            return false;
        }

        $siteName = $this->siteName();
        $subject  = "[{$siteName}] Confirmation de votre commande " . ($orderData['order_reference'] ?? '');

        $vars = $this->buildOrderVars($orderData, $orderItems, $siteName);

        $invoicePdfPath = $this->resolveInvoicePdfPath($orderData);

        return $this->sendTemplatedEmail(
            'email/order-confirmation.html.twig',
            $vars,
            $subject,
            $email,
            $siteName,
            $invoicePdfPath
        );
    }

    /**
     * Envoie l'e-mail de notification de nouvelle commande au vendeur (SANS
     * pièce jointe facture, voir doc de classe).
     *
     * L'adresse de destination est résolue via
     * {@see self::resolveSellerEmail()} : `plugins.simple-shop.seller_email`,
     * ou à défaut `site.author.email`.
     *
     * @param array $orderData  Ligne de la table `order` (voir schéma `shop.sql`).
     * @param array $orderItems Lignes de la table `order_item` associées.
     *
     * @return bool Vrai si l'e-mail a été accepté pour envoi.
     */
    public function sendSellerNotification(array $orderData, array $orderItems): bool
    {
        $sellerEmail = $this->resolveSellerEmail();

        if ($sellerEmail === null) {
            $this->log('error', "Notification vendeur annulée : aucune adresse e-mail vendeur configurée "
                . "(plugins.simple-shop.seller_email / site.author.email), commande '"
                . ($orderData['order_reference'] ?? '?') . "'.");

            return false;
        }

        $siteName = $this->siteName();
        $subject  = "[{$siteName}] Nouvelle commande reçue : " . ($orderData['order_reference'] ?? '');

        $vars = $this->buildOrderVars($orderData, $orderItems, $siteName);

        return $this->sendTemplatedEmail('email/order-seller-notify.html.twig', $vars, $subject, $sellerEmail, $siteName);
    }

    /**
     * Génère (ou récupère depuis le cache disque) le PDF Factur-X d'une
     * commande, de façon strictement NON BLOQUANTE : toute exception est
     * absorbée et journalisée (via `InvoiceManager`/`InvoiceLogger`, voir
     * `logs/simple-shop/invoices.log`), sans jamais interrompre l'envoi de
     * l'e-mail de confirmation.
     *
     * @param array $orderData Ligne de la table `order` (doit contenir `order_reference`).
     *
     * @return string|null Le chemin absolu du PDF, ou null si indisponible
     *                      (référence manquante, échec de génération...).
     */
    private function resolveInvoicePdfPath(array $orderData): ?string
    {
        $orderReference = (string) ($orderData['order_reference'] ?? '');

        if ($orderReference === '') {
            return null;
        }

        try {
            $pdfPath = InvoiceManager::ensurePdfGenerated($orderReference);
        } catch (\Throwable $e) {
            $this->log('error', 'Erreur lors de la génération de la facture Factur-X à joindre à l\'e-mail '
                . "de confirmation (commande '{$orderReference}') : " . $e->getMessage());

            return null;
        }

        if ($pdfPath === null || !file_exists($pdfPath)) {
            $this->log('error', "Facture Factur-X indisponible pour la commande '{$orderReference}' "
                . '(génération échouée) : e-mail de confirmation envoyé sans pièce jointe.');

            return null;
        }

        return $pdfPath;
    }

    /**
     * Construit le tableau de variables Twig commun aux deux templates de
     * commande (confirmation client / notification vendeur).
     *
     * @param array  $orderData  Ligne de la table `order`.
     * @param array  $orderItems Lignes de la table `order_item`.
     * @param string $siteName   Nom du site (site.title).
     *
     * @return array Les variables prêtes pour `processTemplate()`.
     */
    private function buildOrderVars(array $orderData, array $orderItems, string $siteName): array
    {
        return [
            'site_name'       => $siteName,
            'order_reference' => $orderData['order_reference'] ?? '',
            'order_date'      => $orderData['created_at'] ?? '',
            'order_total'     => (float) ($orderData['total_amount'] ?? 0),
            'customer'        => AddressFields::normalize(json_decode((string) ($orderData['customer_data'] ?? '{}'), true) ?: []),
            'shipping'        => json_decode((string) ($orderData['shipping_data'] ?? '{}'), true) ?: [],
            'billing'         => AddressFields::normalize(json_decode((string) ($orderData['billing_data'] ?? '{}'), true) ?: []),
            'order_items'     => $orderItems,
        ];
    }

    /**
     * Résout l'adresse e-mail du vendeur destinataire des notifications de
     * commande : `plugins.simple-shop.seller_email`, ou à défaut
     * `site.author.email`.
     *
     * @return string|null L'adresse e-mail, ou null si aucune configuration
     *                      valide n'a été trouvée.
     */
    private function resolveSellerEmail(): ?string
    {
        $email = $this->grav['config']->get('plugins.simple-shop.seller_email')
            ?: $this->grav['config']->get('site.author.email');

        if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Retourne le nom du site (site.title), avec repli générique.
     *
     * @return string
     */
    private function siteName(): string
    {
        return (string) ($this->grav['config']->get('site.title') ?? 'Notre Boutique');
    }

    /**
     * Résout l'adresse (et le nom) d'expéditeur à partir de
     * `plugins.email.from`, avec repli sur `site.author.email`.
     *
     * Reprend la même logique que `CustomerController::sendOtpEmail()`
     * (dette potentielle de duplication, assumée pour l'instant : les deux
     * usages restent suffisamment proches pour ne pas justifier une
     * extraction supplémentaire à ce stade).
     *
     * @param string $siteName Nom du site, utilisé comme nom d'expéditeur de repli.
     *
     * @return array{0: string|null, 1: string|null} [$fromEmail, $fromName]
     *                                                  (les deux à null si aucune
     *                                                  adresse valide n'a été trouvée).
     */
    private function resolveFromAddress(string $siteName): array
    {
        $fromConfig = $this->grav['config']->get('plugins.email.from');

        if (is_array($fromConfig)) {
            $fromEmail = $fromConfig['email'] ?? null;
            $fromName  = $fromConfig['name'] ?? $siteName;
        } else {
            $fromEmail = $fromConfig;
            $fromName  = $siteName;
        }

        if (empty($fromEmail) || !is_string($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $this->grav['config']->get('site.author.email');
            $fromName  = $siteName;
        }

        if (empty($fromEmail) || !is_string($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return [null, null];
        }

        return [$fromEmail, $fromName];
    }

    /**
     * Rend un template d'e-mail puis l'envoie à un destinataire donné, en y
     * joignant éventuellement un fichier PDF (facture Factur-X, voir
     * {@see self::sendOrderConfirmation()}).
     *
     * @param string      $template       Chemin du template Twig (ex. 'email/order-confirmation.html.twig').
     * @param array       $vars           Variables à injecter dans le template.
     * @param string      $subject        Sujet de l'e-mail.
     * @param string      $toEmail        Adresse e-mail de destination.
     * @param string      $siteName       Nom du site (utilisé pour la résolution de l'expéditeur).
     * @param string|null $attachmentPath Chemin absolu d'un fichier à joindre (PDF), ou null si aucune pièce jointe.
     *
     * @return bool Vrai si l'e-mail a été accepté pour envoi.
     */
    private function sendTemplatedEmail(
        string $template,
        array $vars,
        string $subject,
        string $toEmail,
        string $siteName,
        ?string $attachmentPath = null
    ): bool {
        if (!isset($this->grav['Email'])) {
            $this->log('error', "Le service 'Email' n'est pas disponible : envoi de '{$template}' vers '{$toEmail}' annulé.");

            return false;
        }

        try {
            $html = $this->grav['twig']->processTemplate($template, $vars);
        } catch (\Throwable $e) {
            $this->log('error', "Erreur lors du rendu du template '{$template}' : " . $e->getMessage());

            return false;
        }

        [$fromEmail, $fromName] = $this->resolveFromAddress($siteName);

        if ($fromEmail === null) {
            $this->log('error', "Adresse expéditeur invalide/manquante (plugins.email.from / site.author.email), "
                . "envoi de '{$template}' vers '{$toEmail}' annulé.");

            return false;
        }

        try {
            $message = $this->grav['Email']->message($subject, $html, 'text/html');

            if (!empty($fromName) && is_string($fromName)) {
                $message->setFrom(new Address($fromEmail, $fromName));
            } else {
                $message->setFrom($fromEmail);
            }

            $message->setTo($toEmail);

            // Pièce jointe facture Factur-X (voir doc de classe) : API
            // Symfony Mime (Symfony\Component\Mime\Email::attachFromPath()),
            // utilisée en interne par le service 'Email' de Grav.
            if ($attachmentPath !== null && file_exists($attachmentPath)) {
                $message->attachFromPath($attachmentPath, basename($attachmentPath), 'application/pdf');

                $this->log('info', "Pièce jointe facture ajoutée à l'e-mail vers '{$toEmail}' : "
                    . basename($attachmentPath));
            }

            $this->log('info', "Envoi de '{$template}' vers '{$toEmail}' (sujet : '{$subject}')...");

            $sent = $this->grav['Email']->send($message);

            if (!$sent) {
                $this->log('error', "Email::send() a renvoyé une valeur \"falsy\" pour le template '{$template}' "
                    . "vers '{$toEmail}' (vérifiez la configuration SMTP du plugin Email).");
            }

            return (bool) $sent;
        } catch (\Throwable $e) {
            $this->log('error', "Exception lors de l'envoi du template '{$template}' vers '{$toEmail}' : "
                . $e->getMessage());

            return false;
        }
    }

    /**
     * Écrit une ligne de log, à la fois via le logger Grav (si disponible) et
     * via `error_log()` en filet de sécurité.
     *
     * @param string $level   'info' ou 'error'.
     * @param string $message Message à journaliser.
     *
     * @return void
     */
    private function log(string $level, string $message): void
    {
        $prefix = '[SimpleShop][OrderMailer] ';

        if (isset($this->grav['log'])) {
            try {
                if ($level === 'error') {
                    $this->grav['log']->error($prefix . $message);
                } else {
                    $this->grav['log']->info($prefix . $message);
                }
            } catch (\Throwable $e) {
                // Ignoré : le filet error_log ci-dessous prendra le relais.
            }
        }

        error_log($prefix . strtoupper($level) . ': ' . $message);
    }
}
