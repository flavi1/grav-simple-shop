<?php

namespace Grav\Plugin\SimpleShop\Controllers;

use Grav\Common\Utils;
use Grav\Plugin\Mvc\AbstractController;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;
use Grav\Plugin\SimpleShop\Services\AddressFields;
use Grav\Plugin\SimpleShop\Services\CustomerManager;
use Grav\Plugin\SimpleShop\Services\OtpThrottle;
use Symfony\Component\Mime\Address;

/**
 * Contrôleur client.
 *
 * Centralise la logique d'identification par e-mail (OTP) auparavant située
 * dans `SimpleShopPlugin::identifyRouteHandler()`. Refactorisé pour être
 * réutilisable par :
 *  - la route publique `/identify` (méthode {@see self::identify()}) ;
 *  - la vérification e-mail du tunnel d'achat (C2, {@see self::verifyEmail()}) ;
 *  - la page « Modifier mes informations » (C3, {@see self::account()}) ;
 *  - le rebranchement du tunnel (C4, via `back_url` passé à `verifyEmail()`) ;
 *  - le lien de désabonnement newsletter (D2, {@see self::unsubscribeNewsletter()}).
 *
 * L'envoi de l'e-mail contenant le code OTP est externalisé dans
 * {@see self::sendOtpEmail()} afin d'éviter toute duplication (dette #11).
 * Le corps de cet e-mail est rendu à partir du template dédié
 * `templates/email/otp-code.html.twig` (voir ce fichier).
 *
 * La validation/persistance des données client (formulaire « Mon compte »,
 * et par extension le formulaire d'identité du tunnel de commande) est
 * externalisée dans {@see CustomerManager::handleUserData()} afin d'éviter
 * toute duplication entre `CustomerController::account()` et
 * `CartController::step1Cart()` (action `process_checkout`).
 *
 * FACTORISATION DE LA DÉCISION POST-ENREGISTREMENT (voir échanges de
 * conception) : la décision « faut-il repasser par la vérification e-mail,
 * ou aller directement à la destination finale ? » est désormais centralisée
 * dans {@see CustomerManager::resolvePostSaveDestination()} (méthode pure,
 * sans effet de bord HTTP), partagée avec `CartController::step1Cart()` sans
 * dupliquer le raisonnement.
 *
 * ⚠️ DÉCISION UX (friction minimale, voir échanges de conception) : la page
 * de vérification e-mail ({@see self::verifyEmail()}) ne doit JAMAIS
 * imposer de clic superflu à un client déjà vérifié. En complément de
 * `CartController::step1Cart()` (qui n'y renvoie plus systématiquement,
 * voir `$alwaysVerify = false`), {@see self::verifyEmail()} redirige
 * désormais IMMÉDIATEMENT (sans rendu de page) vers `back_url` si l'e-mail
 * est déjà vérifié, quel que soit le chemin par lequel on atteint cette
 * route (navigation directe, retour arrière du navigateur...). La page
 * "Votre adresse e-mail a bien été validée / Continuer" devient ainsi un
 * filet de sécurité défensif, non atteint en pratique.
 *
 * CORRECTIF (bug « profil écrasé via /identify ») : `handleVerifyOtp()`
 * charge désormais explicitement le profil stocké
 * ({@see CustomerManager::loadPersistedProfileForEmail()}) AVANT toute
 * nouvelle persistance, afin de ne plus écraser silencieusement un profil
 * complet existant lors d'une identification via `/identify` (route
 * indépendante du tunnel de commande, où la session ne contenait jusqu'ici
 * que l'e-mail saisi). Deux cas sont désormais distingués : profil complet
 * restauré (→ `back_url`, voir correctif ci-dessous), ou profil non
 * significatif — fichier "cleared" OU totalement inexistant, traités de
 * façon IDENTIQUE du point de vue client (→ complétion via `account()`,
 * aucune information personnelle à afficher dans les deux cas). Le tunnel de
 * commande (`verifyEmail()`) n'est volontairement PAS concerné par ce
 * correctif : la session y contient déjà les données fraîchement saisies
 * par le client, qu'il ne faut pas écraser par un ancien profil stocké.
 *
 * CORRECTIF (gap « /identify ignore back_url ») : `identify()` accepte
 * désormais un paramètre `back_url` (GET ou POST, validé via
 * {@see self::safeBackUrl()}, comme {@see self::verifyEmail()}), propagé à
 * travers toutes les étapes (demande d'OTP, vérification, formulaire) et
 * utilisé comme destination finale par {@see self::handleVerifyOtp()} au
 * lieu d'une redirection fixe vers l'accueil. Ceci répare notamment le cas
 * `account()` → `identify()` (garde-fou "e-mail non vérifié") → retour
 * effectif sur `account()` (au lieu de l'accueil) une fois l'OTP validé.
 *
 * Le blocage des adresses bannies s'appuie exclusivement sur
 * {@see CustomerManager::isEmailBanned()} (registre en clair
 * `user://data/simple-shop/banned.list`), à chaque point d'entrée sensible :
 * demande d'OTP, validation du code, tunnel d'achat, sauvegarde du formulaire
 * « Mon compte ».
 *
 * Conformément au contrat de la couche MVC (A3/A5), CHAQUE action publique
 * RETOURNE un objet {@see Response} : ce contrôleur n'écrit jamais d'en-tête,
 * ne fait jamais `exit` et ne fait jamais de rendu Twig lui-même.
 *
 * @package Grav\Plugin\SimpleShop\Controllers
 */
class CustomerController extends AbstractController
{
    /**
     * Nom du nonce protégeant le formulaire d'identification (/identify).
     *
     * @var string
     */
    private const NONCE_IDENTIFY = 'shop-identify';

    /**
     * Nom du nonce protégeant la vérification e-mail du tunnel.
     *
     * @var string
     */
    private const NONCE_EMAIL_VERIFICATION = 'shop-email-verification';

    /**
     * Nom du nonce protégeant la page « Modifier mes informations » (C3).
     *
     * @var string
     */
    private const NONCE_ACCOUNT = 'shop-account';

    /** Nonce du bouton de déconnexion. */
    private const NONCE_LOGOUT = 'shop-logout';

    /**
     * Nombre maximal de tentatives de saisie du code OTP avant blocage.
     *
     * @var int
     */
    private const MAX_OTP_ATTEMPTS = 5;

    /**
     * Message affiché lorsqu'une adresse e-mail est bannie.
     *
     * @var string
     */
    private const BANNED_MESSAGE = 'Cette adresse e-mail est bannie. Veuillez nous contacter.';

    /**
     * Secret de repli utilisé pour signer les liens de désabonnement
     * newsletter si aucune configuration dédiée n'est définie.
     *
     * ⚠️ Peu robuste : à ne considérer que comme filet de sécurité. Il est
     * recommandé de définir `plugins.simple-shop.newsletter_unsubscribe_secret`
     * dans la configuration du plugin (blueprint à exposer, cf. rappel).
     *
     * @var string
     */
    private const UNSUBSCRIBE_SECRET_FALLBACK = 'simple-shop-unsubscribe-secret';

    /**
     * Récupère le gestionnaire client depuis le conteneur Grav.
     *
     * @return CustomerManager Le service de gestion du client.
     */
    private function customerManager(): CustomerManager
    {
        return $this->grav['simple_shop_customer'];
    }

    /**
     * Lit la configuration du plugin simple-shop.
     *
     * @return array Les paramètres de configuration (peut être vide).
     */
    private function shopConfig(): array
    {
        return $this->pluginConfig('simple-shop');
    }

    /**
     * Calcule une URL absolue de route à partir de la configuration des routes.
     *
     * Cherche la valeur dans `plugins.simple-shop.routes.<key>` puis, à défaut,
     * utilise le chemin de repli fourni. Le résultat est absolu (préfixé par
     * `base_url_absolute`) pour être utilisable dans une redirection.
     *
     * @param string $key      Clé de route (ex. « identify »).
     * @param string $fallback Chemin de repli si la clé n'est pas configurée.
     *
     * @return string L'URL absolue de la route.
     */
    private function routeUrl(string $key, string $fallback): string
    {
        $routes = (array) ($this->shopConfig()['routes'] ?? []);

        $route = $routes[$key] ?? $fallback;
        if (!is_string($route) || $route === '') {
            $route = $fallback;
        }

        return $this->absoluteUrl($route);
    }

    /**
     * Valide une URL de retour interne (anti open-redirect).
     *
     * N'accepte qu'un chemin relatif commençant par « / » (hors « // ») ou une
     * URL absolue partageant l'hôte de `base_url_absolute`. Toute autre valeur
     * est remplacée par le repli.
     *
     * @param string|null $url      URL candidate (issue de POST/GET).
     * @param string      $fallback URL de repli si l'URL est jugée non sûre.
     *
     * @return string Une URL sûre.
     */
    private function safeBackUrl(?string $url, string $fallback): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $fallback;
        }

        // Chemin relatif interne : autorisé, sauf « // » (protocole relatif).
        if ($url[0] === '/' && (strlen($url) === 1 || $url[1] !== '/')) {
            return $url;
        }

        // URL absolue : autorisée seulement si même hôte que le site.
        $baseAbsolute = (string) ($this->grav['base_url_absolute'] ?? '');
        $baseHost     = parse_url($baseAbsolute, PHP_URL_HOST);
        $urlHost      = parse_url($url, PHP_URL_HOST);

        if ($baseHost !== null && $urlHost !== null && strcasecmp((string) $baseHost, (string) $urlHost) === 0) {
            return $url;
        }

        return $fallback;
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
        $prefix = '[SimpleShop][CustomerController][OTP] ';

        if (isset($this->grav['log'])) {
            try {
                if ($level === 'error') {
                    $this->grav['log']->error($prefix . $message);
                } else {
                    $this->grav['log']->info($prefix . $message);
                }
            } catch (\Throwable $e) {
                // On ignore : le filet error_log ci-dessous prendra le relais.
            }
        }

        // Filet de sécurité systématique, indépendant de la config Grav.
        error_log($prefix . strtoupper($level) . ': ' . $message);
    }

    /**
     * Diagnostique pourquoi le service 'Email' n'est pas disponible.
     *
     * @return void
     */
    private function diagnoseMissingEmailService(): void
    {
        $pluginsService = $this->grav['plugins'] ?? null;

        if ($pluginsService === null) {
            $this->log('error', "Impossible d'interroger le gestionnaire de plugins Grav "
                . "(\$this->grav['plugins'] n'est pas disponible).");
            return;
        }

        if ($pluginsService->get('email') === null) {
            $this->log('error', "Le plugin 'email' n'est PAS chargé.");
            return;
        }
    }

    /**
     * Envoie l'e-mail contenant le code OTP au client.
     *
     * Méthode réutilisable par la vérification e-mail du tunnel (C2), la page
     * « Mon compte » (C3) et le rebranchement du tunnel (C4). Toute la logique
     * d'envoi doit rester ici pour éviter la duplication (dette #11).
     *
     * Le corps de l'e-mail est rendu à partir du template dédié
     * `templates/email/otp-code.html.twig` via
     * `$this->grav['twig']->processTemplate()`, puis envoyé en `text/html`.
     *
     * NOTE DIAGNOSTIC : cette méthode journalise désormais chaque étape
     * (via {@see self::log()}) afin d'identifier précisément où un envoi
     * échoue silencieusement (adresse invalide, plugin Email désactivé,
     * expéditeur invalide, échec `send()` sans exception, etc.). En cas
     * d'absence du service 'Email', {@see self::diagnoseMissingEmailService()}
     * approfondit le diagnostic (plugin non chargé vs. ordre d'initialisation).
     *
     * IMPORTANT (correctif) : le plugin Email de Grav s'appuie en interne sur
     * Symfony Mailer, dont `Email::setFrom()` (Symfony\Component\Mime\Email)
     * n'accepte QUE des chaînes de caractères ou des instances de
     * `Symfony\Component\Mime\Address` — jamais un tableau associatif au
     * format PHPMailer (`[email => nom]`). Passer un tableau provoque
     * l'exception `Symfony\Component\Mime\Exception\InvalidArgumentException:
     * An address can be an instance of Address or a string ("array" given)`.
     * On construit donc explicitement un objet `Address` lorsque le nom de
     * l'expéditeur est disponible.
     *
     * @param string $email Adresse e-mail de destination.
     * @param string $code  Code OTP à transmettre.
     *
     * @return bool Vrai si l'e-mail a été accepté pour envoi, faux sinon.
     */
    public function sendOtpEmail(string $email, string $code): bool
    {
        $email = trim($email);

        $this->log('info', "Appel sendOtpEmail() pour l'adresse : '{$email}'");

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log('error', "Adresse e-mail invalide ou vide, envoi annulé : '{$email}'");

            return false;
        }

        $siteName = (string) ($this->grav['config']->get('site.title') ?? 'Notre Boutique');
        $subject  = "[{$siteName}] Votre code de connexion sécurisé";

        // Le corps du message est rendu à partir du template dédié
        // 'email/otp-code.html.twig' (voir templates/email/otp-code.html.twig),
        // afin de bénéficier de la mise en forme HTML standard du plugin
        // Email de Grav (extends 'email/base.html.twig').
        try {
            $htmlBody = $this->grav['twig']->processTemplate('email/otp-code.html.twig', [
                'site_name' => $siteName,
                'code'      => $code,
            ]);
        } catch (\Throwable $e) {
            $this->log('error', "Erreur lors du rendu du template 'email/otp-code.html.twig' : " . $e->getMessage());

            return false;
        }

        // Le plugin "Email" de Grav doit être activé (plugins.email.enabled)
        // pour que le service 'Email' soit enregistré dans le conteneur.
        $emailPluginEnabled = (bool) $this->grav['config']->get('plugins.email.enabled', false);
        $this->log('info', 'Plugin Grav "Email" activé (plugins.email.enabled) ? ' . ($emailPluginEnabled ? 'oui' : 'non'));

        // Utilise le service e-mail de Grav s'il est disponible.
        if (!isset($this->grav['Email'])) {
            $this->log('error', "Le service 'Email' n'est pas disponible dans le conteneur Grav. "
                . 'Vérifiez que le plugin Email est bien activé ET configuré (Admin > Plugins > Email, '
                . 'ou fichier user/config/plugins/email.yaml).');

            $this->diagnoseMissingEmailService();

            return false;
        }

        try {
            // Récupération sécurisée de l'expéditeur (peut être un string ou un array selon la config admin)
            $fromConfig = $this->grav['config']->get('plugins.email.from');

            if (is_array($fromConfig)) {
                // Si la config est un tableau (ex: ['email' => '...', 'name' => '...'])
                $fromEmail = $fromConfig['email'] ?? null;
                $fromName  = $fromConfig['name'] ?? $siteName;
            } else {
                $fromEmail = $fromConfig;
                $fromName  = $siteName;
            }

            if (empty($fromEmail) || !is_string($fromEmail)) {
                $host = method_exists($this, 'request') ? $this->request()->getServerParam('HTTP_HOST', 'localhost') : ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $fromEmail = $this->grav['config']->get('site.author.email', 'noreply@' . $host);
                $fromName  = $siteName;
            }

            $this->log('info', "Expéditeur retenu pour l'envoi OTP : '{$fromEmail}' (nom affiché : '{$fromName}')");

            if (empty($fromEmail) || !is_string($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $this->log('error', "Adresse expéditeur invalide ou manquante : '" . var_export($fromEmail, true) . "'. "
                    . 'Vérifiez plugins.email.from (Admin > Plugins > Email) ou site.author.email.');

                return false;
            }

            $message = $this->grav['Email']->message($subject, $htmlBody, 'text/html');

            // Application de l'expéditeur au format attendu par Symfony Mailer
            // (Address ou string), JAMAIS un tableau associatif de type PHPMailer.
            if (!empty($fromName) && is_string($fromName)) {
                $message->setFrom(new Address($fromEmail, $fromName));
            } else {
                $message->setFrom($fromEmail);
            }

            $message->setTo($email);

            $this->log('info', "Envoi du message OTP en cours vers '{$email}' (sujet : '{$subject}')...");

            $sent = $this->grav['Email']->send($message);

            $this->log('info', 'Résultat brut de Email::send() : ' . var_export($sent, true));

            if (!$sent) {
                $this->log('error', "Email::send() a renvoyé une valeur \"falsy\" sans lever d'exception. "
                    . "Cela indique généralement un échec de configuration SMTP/sendmail du plugin Email "
                    . '(hôte, port, identifiants, TLS...). Vérifiez la configuration dans Admin > Plugins > Email '
                    . 'et testez l\'envoi via l\'outil de test intégré du plugin Email.');
            }

            return (bool) $sent;
        } catch (\Throwable $e) {
            $this->log('error', 'Exception levée pendant l\'envoi du mail OTP : ' . $e->getMessage()
                . ' (classe : ' . get_class($e) . ')');

            return false;
        }
    }
    /**
     * Route publique d'identification par e-mail (« /identify »).
     *
     * Reprend la logique de l'ancien `identifyRouteHandler()` :
     *  - POST `action = request_otp` : valide l'e-mail, génère et envoie l'OTP ;
     *  - POST `action = verify_otp`  : vérifie le code et gère les tentatives ;
     *  - GET : rend le formulaire d'identification.
     *
     * Le formulaire est protégé par le nonce {@see self::NONCE_IDENTIFY}.
     *
     * CORRECTIF (voir doc de classe) : accepte désormais un `back_url` (GET
     * ou POST, POST prioritaire), validé via {@see self::safeBackUrl()} (repli
     * sur l'accueil), propagé à travers toutes les sous-méthodes et utilisé
     * comme destination finale par {@see self::handleVerifyOtp()}.
     *
     * @return Response La réponse MVC (page virtuelle ou redirection).
     */
    public function identify(): Response
    {
        $backUrlRaw = (string) $this->post('back_url', FILTER_DEFAULT, '');
        if ($backUrlRaw === '') {
            $backUrlRaw = (string) $this->get('back_url', FILTER_DEFAULT, '');
        }
        $backUrl = $this->safeBackUrl($backUrlRaw, $this->absoluteUrl('/'));

        // Traitement des soumissions POST.
        if ($this->isPost()) {
            $nonce = (string) $this->post('nonce', FILTER_DEFAULT, '');
            if ($nonce === '' || !Utils::verifyNonce($nonce, self::NONCE_IDENTIFY)) {
                $this->addMessage('Votre session a expiré, veuillez réessayer.', 'error');

                return $this->redirect($this->withBackUrl($this->routeUrl('identify', '/identify'), $backUrl), 303);
            }

            $action = (string) $this->post('action', FILTER_DEFAULT, '');

            if ($action === 'request_otp') {
                return $this->handleRequestOtp((string) $this->post('email', FILTER_DEFAULT, ''), $backUrl);
            }

            if ($action === 'verify_otp') {
                return $this->handleVerifyOtp((string) $this->post('verification_code', FILTER_DEFAULT, ''), $backUrl);
            }
        }

        // Rendu GET du formulaire.
        return $this->renderIdentifyForm($backUrl);
    }

    /**
     * Traite la demande d'envoi d'un code OTP (action « request_otp »).
     *
     * Vérifie d'abord que l'adresse n'est pas bannie
     * ({@see CustomerManager::isEmailBanned()}, registre `banned.list`) afin
     * de ne jamais envoyer d'OTP à un compte banni.
     *
     * @param string $emailRaw Valeur brute de l'e-mail soumis.
     * @param string $backUrl  URL de retour à propager (voir {@see self::identify()}).
     *
     * @return Response Une redirection vers la route d'identification.
     */
    private function handleRequestOtp(string $emailRaw, string $backUrl): Response
    {
        $identifyUrl = $this->routeUrl('identify', '/identify');

        $email = filter_var(trim($emailRaw), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $this->addMessage('Veuillez saisir une adresse e-mail valide.', 'error');

            return $this->redirect($this->withBackUrl($identifyUrl, $backUrl), 303);
        }

        $targetEmail = trim($email);

        $manager = $this->customerManager();

        if ($manager->isEmailBanned($targetEmail)) {
            $this->addMessage(self::BANNED_MESSAGE, 'error');

            return $this->redirect($this->withBackUrl($identifyUrl, $backUrl), 303);
        }

        $manager->setCustomerData(['email' => $targetEmail]);

        // Anti-spam (délai + plafonds) : voir deliverOtp(). Le message
        // d'échec est déjà posé par deliverOtp().
        if (!$this->deliverOtp($manager, $targetEmail, true)) {
            return $this->redirect($this->withBackUrl($identifyUrl, $backUrl), 303);
        }

        $this->addMessage("Un code de connexion a été envoyé à l'adresse : {$targetEmail}", 'success');

        return $this->redirect($this->withBackUrl($identifyUrl . '?action=verify_otp', $backUrl), 303);
    }

    /**
     * Traite la vérification du code OTP (action « verify_otp »).
     *
     * Revérifie le statut "banni" ({@see CustomerManager::isEmailBanned()})
     * après validation du code : une fenêtre de quelques minutes existe entre
     * la demande d'OTP et sa saisie, pendant laquelle un compte pourrait être
     * banni côté admin.
     *
     * CORRECTIF (bug « profil écrasé ») : charge désormais explicitement le
     * profil stocké via {@see CustomerManager::loadPersistedProfileForEmail()}
     * avant toute persistance, et distingue deux cas :
     *  - profil complet restauré (nom, adresse, panier éventuel...) : message
     *    de succès classique, persistance (rotation du jeton), redirection
     *    vers `$backUrl` (voir CORRECTIF gap `back_url` ci-dessous) ;
     *  - profil NON significatif — que le fichier soit "cleared" (`email` +
     *    `_persist` seulement) ou totalement inexistant, les deux cas sont
     *    traités de façon identique du point de vue client (aucune donnée
     *    personnelle à afficher dans les deux cas) : redirection vers
     *    « Mon compte » pour compléter le profil, EN CONSERVANT `back_url`
     *    (afin qu'`account()` puisse à son tour rediriger vers la destination
     *    d'origine une fois le profil complété).
     *
     * CORRECTIF (gap « /identify ignore back_url », voir doc de classe) : la
     * redirection de succès utilise désormais `$backUrl` au lieu d'une URL
     * d'accueil fixe.
     *
     * @param string $codeRaw Code saisi par l'utilisateur (espaces tolérés).
     * @param string $backUrl URL de retour à utiliser en cas de succès (voir
     *                         {@see self::identify()}).
     *
     * @return Response Une redirection (`back_url`, « Mon compte », ou
     *                   formulaire en cas d'échec de vérification).
     */
    private function handleVerifyOtp(string $codeRaw, string $backUrl): Response
    {
        $identifyUrl = $this->routeUrl('identify', '/identify');

        $inputCode = preg_replace('/\s+/', '', $codeRaw);

        $manager = $this->customerManager();

        if ($manager->verifyEmailCode($inputCode)) {
            $verifiedEmail = trim((string) ($manager->getCustomerData()['email'] ?? ''));

            if ($manager->isEmailBanned($verifiedEmail)) {
                $manager->resetEmailVerification();
                $this->addMessage(self::BANNED_MESSAGE, 'error');

                return $this->redirect($this->withBackUrl($identifyUrl, $backUrl), 303);
            }

            if ($manager->loadPersistedProfileForEmail($verifiedEmail)) {
                // Profil complet restauré (nom, adresse, panier éventuel...).
                $this->addMessage('Vous êtes connecté avec succès !', 'success');

                // Rotation du jeton persistant / renouvellement du cookie.
                $manager->handleDataPersistence();

                return $this->redirect($backUrl, 303);
            }

            // Profil non significatif (fichier "cleared" ou inexistant, mêmes
            // conséquences côté client) : on invite le client à compléter son
            // profil via la page « Modifier mes informations » (réutilisation
            // de account(), plutôt qu'une page dédiée, pour éviter toute
            // duplication de formulaire), EN CONSERVANT back_url afin
            // qu'account() puisse à son tour rediriger vers la destination
            // d'origine une fois le profil complété.
            $this->addMessage(
                'Votre adresse email a bien été validée. Merci de compléter vos informations.',
                'info'
            );

            $accountUrl = $this->routeUrl('account', '/mon-compte');

            return $this->redirect($this->withBackUrl($accountUrl, $backUrl), 303);
        }

        $attempts = $manager->getAttempts();
        if ($attempts > self::MAX_OTP_ATTEMPTS) {
            $this->addMessage('Trop de tentatives infructueuses. Veuillez refaire une demande de code.', 'error');

            return $this->redirect($this->withBackUrl($identifyUrl, $backUrl), 303);
        }

        $remaining = self::MAX_OTP_ATTEMPTS - $attempts;
        $this->addMessage("Le code saisi est incorrect. Il vous reste {$remaining} tentative(s).", 'error');

        return $this->redirect($this->withBackUrl($identifyUrl . '?action=verify_otp', $backUrl), 303);
    }

    /**
     * Prépare et rend le formulaire d'identification (GET).
     *
     * Reproduit les variables Twig exposées par l'ancien handler :
     * `customer`, `simple_shop_config`, `shop_cart`, `is_verified`, `action`,
     * complétées par `back_url` (voir doc de classe) afin que le template
     * puisse le propager dans ses formulaires.
     *
     * @param string $backUrl URL de retour à propager (voir {@see self::identify()}).
     *
     * @return Response La page virtuelle du formulaire d'identification.
     */
    private function renderIdentifyForm(string $backUrl): Response
    {
        $manager = $this->customerManager();

        $currentAction = (string) $this->get('action', FILTER_DEFAULT, '');
        if ($currentAction === '') {
            $currentAction = 'request_otp';
        }

        $customerData = $manager->getCustomerData();
        $shopCartData = CartStorage::getCartItems(CartStorage::getOrCreateCartId());

        return $this->view('simple-shop/identify', [
            'customer'           => $customerData,
            'simple_shop_config' => $this->shopConfig(),
            'shop_cart'          => $shopCartData,
            'is_verified'        => $manager->isEmailVerified(),
            'action'             => $currentAction,
            'back_url'           => $backUrl,
        ]);
    }


    /**
     * Vérification de l'e-mail dans le tunnel d'achat.
     *
     *
     * Flux :
     *  - Garde-fou : un e-mail client doit exister en session, sinon on renvoie
     *    vers `/identify` (avec `back_url` conservé).
     *  - Garde-fou : un e-mail banni ({@see CustomerManager::isEmailBanned()})
     *    est bloqué avant tout traitement (envoi/renvoi/vérification de code).
     *  - GARDE-FOU UX (friction minimale, voir doc de classe) : si l'e-mail
     *    est DÉJÀ vérifié, on redirige IMMÉDIATEMENT vers `back_url`, sans
     *    jamais rendre la page "Votre adresse e-mail a bien été validée /
     *    Continuer" — celle-ci n'apporterait qu'un clic superflu. Ce garde-fou
     *    couvre notamment la navigation directe/retour arrière vers cette URL
     *    après une vérification déjà effectuée.
     *  - POST `verify_code`  : valide le code ; au succès, `handleDataPersistence()`
     *    puis redirection vers `back_url` (sûr) ou accueil.
     *  - POST `resend_code`  : `resetEmailVerification()` puis renvoi du code.
     *  - GET : (ré)envoi automatique du code si l'e-mail n'est pas encore vérifié
     *    (ou si la cible a changé), puis rendu du template.
     *
     * NOTE : contrairement à {@see self::handleVerifyOtp()}, cette méthode ne
     * recharge PAS le profil stocké depuis le fichier : la session contient
     * déjà les données fraîchement saisies par le client dans le formulaire
     * de commande (`CartController::step1Cart()` → `CustomerManager::handleUserData()`),
     * qu'il ne faut pas écraser par un ancien profil persisté (ex. nouvelle
     * adresse volontairement différente de l'ancienne).
     *
     * Nonce : {@see self::NONCE_EMAIL_VERIFICATION} (inchangé, pas d'unification).
     *
     * @return Response La réponse MVC (page virtuelle ou redirection).
     */
    public function verifyEmail(): Response
    {
        $manager = $this->customerManager();

        // Détermine l'URL de retour (POST prioritaire sur GET).
        $backUrlRaw = (string) $this->post('back_url', FILTER_DEFAULT, '');
        if ($backUrlRaw === '') {
            $backUrlRaw = (string) $this->get('back_url', FILTER_DEFAULT, '');
        }
        $backUrl = $this->safeBackUrl($backUrlRaw, $this->absoluteUrl('/'));

        $verifyUrl = $this->routeUrl('validation', '/checkout/email');

        // Garde-fou : un e-mail client doit être présent en session.
        $customer = $manager->getCustomerData();
        $email    = trim((string) ($customer['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addMessage('Veuillez d’abord renseigner votre adresse e-mail.', 'error');

            $identifyUrl = $this->routeUrl('identify', '/identify');
            if ($backUrlRaw !== '') {
                $identifyUrl .= (strpos($identifyUrl, '?') === false ? '?' : '&')
                    . 'back_url=' . rawurlencode($backUrl);
            }

            return $this->redirect($identifyUrl, 303);
        }

        // Garde-fou : un compte banni ne doit jamais pouvoir recevoir ou
        // vérifier de code OTP dans le tunnel d'achat (couvre resend_code,
        // verify_code et le renvoi automatique en GET ci-dessous).
        if ($manager->isEmailBanned($email)) {
            $manager->resetEmailVerification();
            $this->addMessage(self::BANNED_MESSAGE, 'error');

            return $this->redirect($this->routeUrl('identify', '/identify'), 303);
        }

        // GARDE-FOU UX (friction minimale, voir doc de classe et de méthode) :
        // si l'e-mail est déjà vérifié, on ne montre plus JAMAIS la page
        // intermédiaire "déjà validé / Continuer" — redirection immédiate.
        if ($manager->isEmailVerified()) {
            return $this->redirect($backUrl, 303);
        }

        // Traitement des soumissions POST.
        if ($this->isPost()) {
            $nonce = (string) $this->post('nonce', FILTER_DEFAULT, '');
            if ($nonce === '' || !Utils::verifyNonce($nonce, self::NONCE_EMAIL_VERIFICATION)) {
                $this->addMessage('Votre session a expiré, veuillez réessayer.', 'error');

                return $this->redirect($verifyUrl, 303);
            }

            $action = (string) $this->post('action', FILTER_DEFAULT, '');

            // Renvoi d'un nouveau code.
            if ($action === 'resend_code') {
                if ($this->deliverOtp($manager, $email, true)) {
                    $this->addMessage("Un nouveau code a été envoyé à l'adresse : {$email}", 'success');
                }

                return $this->redirect($this->withBackUrl($verifyUrl, $backUrl), 303);
            }

            // Vérification du code saisi.
            if ($action === 'verify_code') {
                $inputCode = preg_replace('/\s+/', '', (string) $this->post('verification_code', FILTER_DEFAULT, ''));

                if ($manager->verifyEmailCode($inputCode)) {
                    // Persistance (cookie / auto-login géré en D1).
                    $manager->handleDataPersistence();

                    $this->addMessage('Adresse e-mail vérifiée avec succès.', 'success');

                    return $this->redirect($backUrl, 303);
                }

                $attempts = $manager->getAttempts();
                if ($attempts > self::MAX_OTP_ATTEMPTS) {
                    $this->addMessage('Trop de tentatives infructueuses. Un nouveau code va vous être demandé.', 'error');

                    return $this->redirect($this->withBackUrl($verifyUrl, $backUrl), 303);
                }

                $remaining = self::MAX_OTP_ATTEMPTS - $attempts;
                $this->addMessage("Le code saisi est incorrect. Il vous reste {$remaining} tentative(s).", 'error');

                return $this->redirect($this->withBackUrl($verifyUrl, $backUrl), 303);
            }
        }

        // GET : (ré)envoi automatique du code si nécessaire. À ce stade,
        // l'e-mail n'est JAMAIS déjà vérifié (voir garde-fou ci-dessus).
        // Un simple rechargement de la page ne doit JAMAIS renvoyer d'e-mail :
        // on n'envoie que s'il n'y a pas déjà un code valide pour cette adresse.
        if (!$manager->hasPendingOtp($email)
            && $this->deliverOtp($manager, $email, false)
        ) {
            $this->addMessage("Un code de vérification a été envoyé à l'adresse : {$email}", 'info');
        }

        // Rendu de la page de vérification. `email_verified` est toujours
        // faux à ce stade (redirection immédiate ci-dessus sinon) : la
        // branche "déjà validé" du template devient un filet de sécurité
        // défensif, jamais atteint en pratique.
        return $this->view('simple-shop/email-validation', [
            'customer_email'     => $email,
            'email_verified'     => $manager->isEmailVerified(),
            'next_step_url'      => $backUrl,
            'back_url'           => $backUrl,
            'customer'           => $manager->getCustomerData(),
            'simple_shop_config' => $this->shopConfig(),
        ]);
    }

    /**
     * Envoie un code OTP en appliquant l'anti-spam, 100 % côté serveur.
     *
     * Ordre des contrôles (tout refus pose un message flash neutre et
     * retourne faux, SANS rien envoyer ni invalider le code en cours) :
     *  1. délai minimal entre deux envois pour la session
     *     (`plugins.simple-shop.otp.resend_delay`, 60 s) ;
     *  2. plafonds par adresse destinataire et par IP sur une heure
     *     ({@see OtpThrottle}, indépendants de la session).
     *
     * @param CustomerManager $manager Gestionnaire de session client.
     * @param string          $email   Adresse destinataire (déjà validée).
     * @param bool            $newCode Vrai pour invalider le code en cours et
     *                                 en générer un nouveau ; faux pour
     *                                 réutiliser un code encore valide.
     *
     * @return bool Vrai si un e-mail a été envoyé.
     */
    private function deliverOtp(CustomerManager $manager, string $email, bool $newCode): bool
    {
        $remaining = $manager->otpCooldownRemaining();
        if ($remaining > 0) {
            $this->addMessage(
                "Un code vient d'être envoyé. Patientez encore {$remaining} seconde(s) avant d'en demander un nouveau.",
                'error'
            );

            return false;
        }

        $reason = $this->otpThrottle()->acquire($email, $this->clientIp());
        if ($reason !== null) {
            $this->log('error', "Envoi OTP refusé (plafond horaire atteint, motif : {$reason}).");
            $this->addMessage('Trop de demandes de code. Merci de réessayer plus tard.', 'error');

            return false;
        }

        if ($newCode) {
            $manager->resetEmailVerification();
        }

        $code = $manager->getOrGenerateEmailVerificationCode($email);

        if (!$this->sendOtpEmail($email, $code)) {
            $this->addMessage("Erreur technique lors de l'envoi du code.", 'error');

            return false;
        }

        $manager->markOtpSent();

        return true;
    }

    /**
     * Construit le limiteur d'envois OTP (fichier JSON dans `user://data/simple-shop/`).
     */
    private function otpThrottle(): OtpThrottle
    {
        $config = $this->grav['config'];
        $dir    = $this->grav['locator']->findResource('user://data/simple-shop', true, true);

        return new OtpThrottle(
            $dir . '/otp-throttle.json',
            $this->unsubscribeSecret(),
            (int) $config->get('plugins.simple-shop.otp.max_per_email_hour', 5),
            (int) $config->get('plugins.simple-shop.otp.max_per_ip_hour', 20)
        );
    }

    /**
     * Adresse IP du client (Grav tient compte de `system.http_x_forwarded`).
     */
    private function clientIp(): string
    {
        return (string) $this->grav['uri']->ip();
    }

    /**
     * Ajoute le paramètre `back_url` à une URL de redirection interne.
     *
     * @param string $url     URL de base (déjà absolue).
     * @param string $backUrl URL de retour à propager.
     *
     * @return string L'URL enrichie du paramètre `back_url`.
     */
    private function withBackUrl(string $url, string $backUrl): string
    {
        if ($backUrl === '') {
            return $url;
        }

        return $url . (strpos($url, '?') === false ? '?' : '&')
            . 'back_url=' . rawurlencode($backUrl);
    }

    /**
     * Page « Modifier mes informations personnelles ».
     *
     * Permet à un client identifié (e-mail vérifié) de consulter et modifier ses
     * informations, indépendamment du tunnel d'achat.
     *
     * Flux :
     *  - Garde-fou : e-mail vérifié requis, sinon redirection vers `/identify`
     *    avec `back_url` pointant vers cette page (désormais effectivement
     *    honoré par `/identify`, voir doc de classe).
     *  - GET : formulaire pré-rempli via `CustomerManager::getCustomerData()`
     *    (vide si aucun profil n'a encore été renseigné/persisté — c'est un
     *    cas d'usage normal : un client identifié doit pouvoir accéder à
     *    « Mon compte » même sans profil existant, ne serait-ce que pour
     *    cocher "Se souvenir de moi" ou saisir/modifier son adresse de
     *    livraison), complété par l'URL de désabonnement newsletter
     *    (`unsubscribe_url`).
     *  - POST : nonce {@see self::NONCE_ACCOUNT}, nettoyage des champs (SANS
     *    `accept-cgv`), délégation de la validation/persistance à
     *    {@see CustomerManager::handleUserData()}, puis délégation de la
     *    DÉCISION de redirection à
     *    {@see CustomerManager::resolvePostSaveDestination()} (politique
     *    `$alwaysVerify = false` : on ne repasse par la vérification que si
     *    l'e-mail vient de changer — voir doc de classe).
     *
     * @return Response La réponse MVC (page virtuelle ou redirection).
     */
    public function account(): Response
    {
        $manager     = $this->customerManager();
        $accountUrl  = $this->routeUrl('account', '/mon-compte');

        // Garde-fou : e-mail vérifié obligatoire.
        if (!$manager->isEmailVerified()) {
            $this->addMessage('Veuillez vous identifier pour accéder à vos informations.', 'info');

            $identifyUrl = $this->routeUrl('identify', '/identify')
                . '?back_url=' . rawurlencode($accountUrl);

            return $this->redirect($identifyUrl, 303);
        }

        // Traitement de la soumission.
        if ($this->isPost()) {
            $nonce = (string) $this->post('nonce', FILTER_DEFAULT, '');
            if ($nonce === '' || !Utils::verifyNonce($nonce, self::NONCE_ACCOUNT)) {
                $this->addMessage('Votre session a expiré, veuillez réessayer.', 'error');

                return $this->redirect($accountUrl, 303);
            }

            // Nettoyage des champs texte autorisés (SANS accept-cgv).
            $data = [];
            foreach (CustomerManager::TEXT_FIELDS as $field) {
                $data[$field] = trim((string) $this->post($field, FILTER_DEFAULT, ''));
            }

            // Cases à cocher (présence => coché). keep-data NON obligatoire (E6).
            $data['newsletter'] = $this->post('newsletter', FILTER_DEFAULT, null) !== null;
            $data['keep-data']  = $this->post('keep-data', FILTER_DEFAULT, null) !== null;

            $result = $manager->handleUserData($data);

            if (!$result['success']) {
                if ($result['error'] === 'invalid_email') {
                    $this->addMessage('Veuillez saisir une adresse e-mail valide.', 'error');
                } elseif ($result['error'] === 'banned') {
                    $this->addMessage(self::BANNED_MESSAGE, 'error');
                } elseif ($result['error'] === 'unsupported_zone') {
                    $this->addMessage(AddressFields::UNSUPPORTED_ZONE_MESSAGE, 'error');
                } else {
                    $this->addMessage('Merci de renseigner tous les champs obligatoires.', 'error');
                }

                return $this->redirect($accountUrl, 303);
            }

            // Décision de redirection factorisée (voir doc de classe) :
            // politique $alwaysVerify = false, propre à cette page (hors
            // tunnel) — on ne repasse par la vérification que si l'e-mail a
            // réellement changé.
            $destination = $manager->resolvePostSaveDestination(
                $result['email_changed'],
                $accountUrl,
                $this->routeUrl('validation', '/checkout/email'),
                false
            );

            if ($destination['requires_verification']) {
                $this->addMessage(
                    'Vos informations ont été enregistrées. Votre nouvelle adresse '
                    . 'e-mail doit être vérifiée à nouveau.',
                    'info'
                );
            } else {
                $this->addMessage('Vos informations ont bien été enregistrées.', 'success');
            }

            return $this->redirect($destination['url'], 303);
        }

        $customerData = $manager->getCustomerData();

        // GET : formulaire pré-rempli.
        return $this->view('simple-shop/account', [
            'customer'           => $customerData,
            'is_verified'        => $manager->isEmailVerified(),
            'simple_shop_config' => $this->shopConfig(),
            'unsubscribe_url'    => $this->buildUnsubscribeUrl((string) ($customerData['email'] ?? '')),
        ]);
    }

    /**
     * Déconnexion du client (POST + nonce : une simple visite d'URL, par
     * exemple via une image tierce, ne doit pas déconnecter).
     *
     * Oublie l'e-mail vérifié et supprime le cookie « se souvenir de moi ».
     * Les données conservées volontairement (« keep-data ») ne sont pas
     * effacées. Un GET renvoie simplement vers la page du compte.
     */
    public function logout(): Response
    {
        $accountUrl = $this->routeUrl('account', '/mon-compte');

        if (!$this->isPost()) {
            return $this->redirect($accountUrl, 303);
        }

        $nonce = (string) $this->post('nonce', FILTER_DEFAULT, '');
        if ($nonce === '' || !Utils::verifyNonce($nonce, self::NONCE_LOGOUT)) {
            $this->addMessage('Votre session a expiré, veuillez réessayer.', 'error');

            return $this->redirect($accountUrl, 303);
        }

        $this->customerManager()->logout();
        $this->addMessage('Vous êtes déconnecté.', 'success');

        return $this->redirect($this->routeUrl('cart', '/cart'), 303);
    }

    /**
     * Calcule le secret utilisé pour signer les liens de désabonnement
     * newsletter (HMAC).
     *
     * Recommandation : définir `plugins.simple-shop.newsletter_unsubscribe_secret`
     * dans la configuration du plugin. À défaut, on retombe sur le salt
     * système puis sur l'e-mail de l'auteur du site, moins robustes.
     *
     * @return string Le secret à utiliser pour le HMAC.
     */
    private function unsubscribeSecret(): string
    {
        $config = $this->grav['config'];

        $secret = $config->get('plugins.simple-shop.newsletter_unsubscribe_secret')
            ?: $config->get('system.security.salt')
            ?: $config->get('site.author.email')
            ?: self::UNSUBSCRIBE_SECRET_FALLBACK;

        return (string) $secret;
    }

    /**
     * Calcule le jeton HMAC signant un lien de désabonnement newsletter pour
     * une adresse e-mail donnée.
     *
     * Ce jeton permet de vérifier l'authenticité du lien sans nécessiter de
     * session ni de nonce CSRF classique : le lien est cliqué depuis un
     * client mail, potentiellement plusieurs semaines après son envoi.
     *
     * @param string $email Adresse e-mail concernée.
     *
     * @return string Le jeton HMAC (hexadécimal).
     */
    private function unsubscribeToken(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), $this->unsubscribeSecret());
    }

    /**
     * Construit l'URL absolue de désabonnement newsletter pour une adresse
     * e-mail donnée (à inclure dans les futurs e-mails marketing, ou affichée
     * sur la page « Mon compte »).
     *
     * @param string $email Adresse e-mail concernée.
     *
     * @return string L'URL absolue de désabonnement (chaîne vide si $email est vide).
     */
    public function buildUnsubscribeUrl(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        $url = $this->routeUrl('newsletter_unsubscribe', '/newsletter/unsubscribe');

        return $url
            . '?email=' . rawurlencode($email)
            . '&token=' . $this->unsubscribeToken($email);
    }

    /**
     * Traite le clic sur un lien de désabonnement newsletter (route publique,
     * ex. « /newsletter/unsubscribe?email=...&token=... »), sans nécessiter
     * de session ni d'identification préalable.
     *
     * La logique métier (mise à jour du fichier `_persist`) est intégralement
     * déléguée à {@see CustomerManager::unsubscribeNewsletter()} : ce
     * contrôleur se limite à la vérification du jeton HMAC et au rendu.
     *
     * @return Response La page virtuelle de confirmation.
     */
    public function unsubscribeNewsletter(): Response
    {
        $email = trim((string) $this->get('email', FILTER_DEFAULT, ''));
        $token = (string) $this->get('token', FILTER_DEFAULT, '');

        $isValidRequest = $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && $token !== ''
            && hash_equals($this->unsubscribeToken($email), $token);

        $success = $isValidRequest && $this->customerManager()->unsubscribeNewsletter($email);

        return $this->view('simple-shop/newsletter-unsubscribe', [
            'success'            => $success,
            'email'              => $email,
            'simple_shop_config' => $this->shopConfig(),
        ]);
    }
}
