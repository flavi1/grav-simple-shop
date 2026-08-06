<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;
use Grav\Common\Session;
use Grav\Plugin\SimpleShop\Inventory\CartStorage;

class CustomerManager
{
    /**
     * Liste des champs texte "identité" du client, partagés entre le tunnel
     * de commande (CartController::step1Cart(), step1cart.html.twig) et la
     * page « Modifier mes informations » (CustomerController::account()).
     *
     * @var string[]
     */
    public const TEXT_FIELDS = [
        'lastname',
        'firstname',
        'email',
        'tel',
        'address-line1',
        'postal-code',
        'address-line2',
        'address-line3',
    ];

    /**
     * Champs obligatoires (validation métier) parmi {@see self::TEXT_FIELDS}.
     *
     * ⚠️ CORRECTIF (Bug 4) : `address-line2` (complément d'adresse) a été
     * retiré de cette liste — il s'agit d'un champ optionnel par nature
     * (étage, bâtiment, appartement...), il ne doit jamais être exigé dans
     * le tunnel de vente ni sur la page « Modifier mes informations ».
     *
     * @var string[]
     */
    public const REQUIRED_FIELDS = [
        'email',
        'address-line1',
        'postal-code',
    ];

    /**
     * Nom du fichier registre des adresses bannies (en clair, une adresse par
     * ligne), stocké dans `user://data/simple-shop/banned.list`.
     *
     * IMPORTANT (dette technique assumée) : ce registre en clair est la SEULE
     * source de vérité pour le bannissement (voir {@see self::isEmailBanned()}).
     * `_persist` (dans chaque fichier client) reste réservé à d'autres
     * informations gérées côté admin : `newsletter`, et à terme `groups`
     * (remises partenaires/clients réguliers).
     *
     * @var string
     */
    private const BANNED_LIST_FILENAME = 'banned.list';

    /**
     * Session Grav.
     *
     * @var Session
     */
    protected Session $session;

    /**
     * Conteneur Grav.
     *
     * @var Grav
     */
    protected Grav $grav;

    /**
     * Constructeur du CustomerManager.
     *
     * @param Session $session Session Grav courante.
     * @param Grav    $grav    Conteneur de services Grav.
     */
    public function __construct(Session $session, Grav $grav)
    {
        $this->session = $session;
        $this->grav = $grav;
    }

    /**
     * Récupère les données client en session.
     */
    public function getCustomerData(): array
    {
        return $this->session->shop_order->customer ?? [];
    }

    /**
     * Met à jour ou fusionne les données client.
     */
    public function setCustomerData(array $data): void
    {
        $current = $this->session->shop_order->customer ?? [];
        $this->session->shop_order->customer = array_merge($current, $data);
    }

    /**
     * Traite une soumission de données client (formulaire « Mon compte » ou
     * étape identité du tunnel de commande).
     *
     * Centralise la logique auparavant dupliquée entre
     * `CustomerController::account()` et `CartController::step1Cart()`
     * (action `process_checkout`) :
     *  - validation du format de l'e-mail ;
     *  - validation des champs obligatoires ({@see self::REQUIRED_FIELDS}) ;
     *  - blocage d'un compte banni ({@see self::isEmailBanned()}, basé sur le
     *    registre `banned.list`, jamais sur `_persist`) ;
     *  - fusion du choix "newsletter" avec les informations `_persist`
     *    (newsletter, et à terme `groups`...), TOUJOURS relues depuis le
     *    fichier de stockage (jamais depuis la session), afin de ne jamais
     *    écraser par erreur un statut admin avec des données de session
     *    obsolètes ;
     *  - détection d'un changement d'adresse e-mail par rapport à la session ;
     *  - persistance des données (`setCustomerData()`) ;
     *  - selon le cas : réinitialisation de la vérification e-mail (si l'e-mail
     *    a changé) ou persistance long terme (`handleDataPersistence()`).
     *
     * Cette méthode ne lit JAMAIS directement les superglobales/`Request` :
     * le tableau `$data` doit déjà avoir été nettoyé (trim, etc.) par l'appelant
     * (contrôleur), qui reste seul responsable des aspects HTTP (nonce, POST,
     * redirections, messages flash).
     *
     * @param array $data Données client nettoyées, indexées par nom de champ
     *                     (dont, le cas échéant, les booléens 'newsletter' et
     *                     'keep-data').
     *
     * @return array{success: bool, error: string|null, email_changed: bool}
     *               Résultat du traitement :
     *               - 'success'      : vrai si les données ont été acceptées et enregistrées ;
     *               - 'error'        : null si succès, sinon 'invalid_email', 'missing_required'
     *                                  ou 'banned' ;
     *               - 'email_changed': vrai si l'e-mail diffère de celui déjà en session
     *                                  (implique une réinitialisation de la vérification).
     */
    public function handleUserData(array $data): array
    {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'invalid_email',
                'email_changed' => false,
            ];
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (($data[$field] ?? '') === '') {
                return [
                    'success' => false,
                    'error' => 'missing_required',
                    'email_changed' => false,
                ];
            }
        }

        $newEmail = (string) $data['email'];

        if ($this->isEmailBanned($newEmail)) {
            return [
                'success' => false,
                'error' => 'banned',
                'email_changed' => false,
            ];
        }

        // Les informations _persist (newsletter, groups...) sont TOUJOURS
        // rechargées depuis le fichier de stockage (source de vérité gérée
        // côté admin), jamais depuis la session, afin de ne jamais écraser
        // par erreur un statut admin avec des données de session obsolètes.
        $persist = $this->loadPersistDataForEmail($newEmail);

        if (array_key_exists('newsletter', $data)) {
            $persist['newsletter'] = (bool) $data['newsletter'];
        }
        $data['_persist'] = $persist;
        unset($data['newsletter']);

        $currentEmail = trim((string) ($this->getCustomerData()['email'] ?? ''));
        $emailChanged = strcasecmp($currentEmail, $newEmail) !== 0;

        $this->setCustomerData($data);

        if ($emailChanged) {
            $this->resetEmailVerification();
        } else {
            $this->handleDataPersistence();
        }

        return [
            'success' => true,
            'error' => null,
            'email_changed' => $emailChanged,
        ];
    }

    /**
     * Détermine, après l'enregistrement des données client (voir
     * {@see self::handleUserData()}), si le client doit être redirigé vers
     * l'étape de vérification e-mail (avec retour prévu vers `$successUrl`)
     * ou directement vers `$successUrl`.
     *
     * FACTORISATION (voir échanges de conception) : cette méthode centralise
     * UNIQUEMENT la DÉCISION (aucun effet de bord HTTP — ni redirection, ni
     * message flash, ni vérification de nonce), afin d'être partagée SANS
     * DUPLICATION entre `CartController::step1Cart()` (action
     * `process_checkout`) et `CustomerController::account()`.
     *
     * ⚠️ DÉCISION UX (friction minimale, voir échanges de conception) : les
     * DEUX appelants utilisent désormais `$alwaysVerify = false` — y compris
     * dans le tunnel d'achat. Il n'y a plus de justification à repasser
     * systématiquement par l'étape de vérification e-mail si l'e-mail est
     * déjà vérifié et n'a pas changé : cela n'ajoutait qu'un clic ("Continuer")
     * et un aller-retour HTTP totalement superflus. Le paramètre
     * `$alwaysVerify` est conservé pour ne pas fermer la porte à un besoin
     * futur (ex. forcer une re-vérification périodique), mais n'est plus
     * utilisé nulle part avec la valeur `true` dans ce plugin.
     *
     * Chaque appelant reste seul responsable du calcul de `$successUrl` et
     * `$verifyEmailUrl` (à partir de la configuration des routes), et de la
     * redirection HTTP elle-même (nonce déjà vérifié par l'appelant avant
     * d'en arriver là).
     *
     * @param bool   $emailChanged   Valeur `email_changed` retournée par
     *                                 {@see self::handleUserData()}.
     * @param string $successUrl     URL absolue de destination finale (une
     *                                 fois la vérification, si nécessaire,
     *                                 effectuée).
     * @param string $verifyEmailUrl URL absolue de la route de vérification
     *                                 e-mail à utiliser SI la vérification
     *                                 est requise (sans `back_url` : celui-ci
     *                                 est ajouté par cette méthode).
     * @param bool   $alwaysVerify   Si vrai, impose systématiquement le
     *                                 passage par la vérification, même si
     *                                 l'e-mail n'a pas changé et est déjà
     *                                 vérifié. Non utilisé (`false`) par les
     *                                 appelants actuels (voir ci-dessus).
     *
     * @return array{requires_verification: bool, url: string} La décision :
     *               `requires_verification` indique si `$url` pointe vers la
     *               vérification e-mail (avec `back_url=$successUrl` déjà
     *               ajouté) ou directement vers `$successUrl`.
     */
    public function resolvePostSaveDestination(
        bool $emailChanged,
        string $successUrl,
        string $verifyEmailUrl,
        bool $alwaysVerify = false
    ): array {
        if ($alwaysVerify || $emailChanged || !$this->isEmailVerified()) {
            return [
                'requires_verification' => true,
                'url' => $verifyEmailUrl
                    . (strpos($verifyEmailUrl, '?') === false ? '?' : '&')
                    . 'back_url=' . rawurlencode($successUrl),
            ];
        }

        return [
            'requires_verification' => false,
            'url' => $successUrl,
        ];
    }

    /**
     * Vérifie si l'e-mail actuel est validé.
     */
    public function isEmailVerified(): bool
    {
        return $this->session->shop_order->email_verified ?? false;
    }

    /**
     * Vérifie si un nouveau code peut être demandé (anti-spam / délai minimal de 60s).
     */
    public function canRequestNewOtp(): bool
    {
        $lastSent = $this->session->shop_order->email_verification_last_sent ?? 0;
        return (time() - $lastSent) >= 60;
    }

    /**
     * Génère ou récupère le code OTP pour l'e-mail donné avec TTL (10 minutes).
     */
    public function getOrGenerateEmailVerificationCode(string $targetEmail): string
    {
        $currentCode = $this->session->shop_order->email_verification_code ?? null;
        $currentTarget = $this->session->shop_order->email_verification_target ?? '';
        $expiresAt = $this->session->shop_order->email_verification_expires_at ?? 0;

        $isExpired = time() > $expiresAt;

        if (empty($currentCode) || $currentTarget !== $targetEmail || $isExpired) {
            try {
                $code = (string) random_int(100000, 999999);
            } catch (\Exception $e) {
                $code = (string) mt_rand(100000, 999999);
            }

            $this->session->shop_order->email_verification_code = $code;
            $this->session->shop_order->email_verification_target = $targetEmail;
            $this->session->shop_order->email_verified = false;
            $this->session->shop_order->email_verification_attempts = 0;
            $this->session->shop_order->email_verification_expires_at = time() + 600; // TTL 10 minutes
            $this->session->shop_order->email_verification_last_sent = time();

            return $code;
        }

        return $currentCode;
    }

    /**
     * Valide le code saisi par l'utilisateur avec vérification du TTL et protection constante-temps.
     */
    public function verifyEmailCode(string $inputCode): bool
    {
        // Vérification de l'expiration (TTL)
        $expiresAt = $this->session->shop_order->email_verification_expires_at ?? 0;
        if (time() > $expiresAt) {
            $this->resetEmailVerification();
            return false;
        }

        $attempts = ($this->session->shop_order->email_verification_attempts ?? 0) + 1;
        $this->session->shop_order->email_verification_attempts = $attempts;

        if ($attempts > 5) {
            $this->resetEmailVerification();
            return false;
        }

        $sessionCode = $this->session->shop_order->email_verification_code ?? null;

        if ($sessionCode && hash_equals((string) $sessionCode, (string) $inputCode)) {
            $this->session->shop_order->email_verified = true;
            
            // Nettoyage complet de l'état OTP au succès (Dette #6)
            unset(
                $this->session->shop_order->email_verification_code,
                $this->session->shop_order->email_verification_attempts,
                $this->session->shop_order->email_verification_target,
                $this->session->shop_order->email_verification_expires_at,
                $this->session->shop_order->email_verification_last_sent
            );
            return true;
        }

        return false;
    }

    /**
     * Force la réinitialisation de la vérification (pour renvoi de code).
     */
    public function resetEmailVerification(): void
    {
        unset(
            $this->session->shop_order->email_verification_code,
            $this->session->shop_order->email_verification_target,
            $this->session->shop_order->email_verification_expires_at,
            $this->session->shop_order->email_verification_attempts
        );
        $this->session->shop_order->email_verified = false;
    }

    /**
     * Retourne le nombre de tentatives actuelles.
     */
    public function getAttempts(): int
    {
        return $this->session->shop_order->email_verification_attempts ?? 0;
    }

    /**
     * Détermine si la connexion actuelle est en HTTPS.
     */
    protected function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }

    /**
     * Supprime le cookie "Rolling Token" (Se souvenir de moi), s'il existe,
     * SANS jamais toucher au fichier de persistance utilisateur.
     *
     * À utiliser pour toute anomalie de jeton détectée dans
     * {@see self::attemptAutoLogin()} : un cookie corrompu/expiré/tamperé ne
     * doit JAMAIS entraîner la suppression du profil persisté (qui contient
     * potentiellement des informations `_persist` gérées côté admin comme
     * `groups`, destinées à survivre indépendamment des aléas de connexion).
     */
    private function clearRememberCookie(): void
    {
        if (isset($_COOKIE['simple_shop_remember'])) {
            setcookie('simple_shop_remember', '', time() - 3600, '/', '', $this->isHttps(), true);
        }
    }

    /**
     * Calcule le chemin du fichier de persistance pour un e-mail donné, sans
     * effet de bord (ne crée rien).
     *
     * @param string $email Adresse e-mail (sera normalisée trim + minuscules).
     *
     * @return string Le chemin absolu du fichier (peut ne pas exister).
     */
    private function userFilePathForEmail(string $email): string
    {
        $emailHash = hash('sha256', strtolower(trim($email)));
        $storageDir = $this->grav['locator']->findResource('user://data/simple-shop/users', true, true);

        return $storageDir . '/' . $emailHash . '.json';
    }

    /**
     * Détermine si un tableau de données client contient un profil "réel"
     * (au moins un champ d'identité renseigné au-delà de `email`), par
     * opposition à un profil "vide" (fichier inexistant, ou "cleared" ne
     * contenant plus que `email` + `_persist`).
     *
     * Critère retenu : au moins un des champs {@see self::REQUIRED_FIELDS}
     * autre que `email` (adresse, code postal) doit être renseigné.
     *
     * NOTE : du point de vue utilisateur, un fichier inexistant et un fichier
     * "cleared" sont équivalents (aucune information personnelle à afficher),
     * et sont donc traités de façon identique par les appelants (voir
     * {@see \Grav\Plugin\SimpleShop\Controllers\CustomerController::handleVerifyOtp()}) :
     * dans les deux cas, on invite simplement le client à compléter son
     * profil via « Mon compte ».
     *
     * @param array $data Données client à évaluer (déjà décodées).
     *
     * @return bool Vrai si un profil exploitable est présent.
     */
    private function isProfileDataMeaningful(array $data): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($field === 'email') {
                continue;
            }

            if (!empty($data[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fusionne un profil client stocké (déjà décodé depuis un fichier JSON)
     * dans la session courante, et restaure l'éventuel panier associé si le
     * panier courant est vide.
     *
     * Factorisée depuis {@see self::attemptAutoLogin()} pour être réutilisée
     * par {@see self::loadPersistedProfileForEmail()} (identification via
     * `/identify`, sans passer par le cookie "Se souvenir de moi").
     *
     * @param array $customerData Données client déjà décodées (peut contenir
     *                            `persistent_token`, `updated_at`, `cart_id`,
     *                            qui sont retirés avant fusion en session).
     *
     * @return void
     */
    private function mergeStoredProfileIntoSession(array $customerData): void
    {
        $persistedCartId = isset($customerData['cart_id']) && is_string($customerData['cart_id'])
            ? $customerData['cart_id']
            : null;

        unset($customerData['persistent_token'], $customerData['updated_at'], $customerData['cart_id']);

        $this->setCustomerData($customerData);
        $this->session->shop_order->email_verified = true;

        // Restauration conditionnelle de l'ancien panier (uniquement si le
        // panier courant est vide). ⚠️ Dette : les quantités restaurées ne
        // sont pas revalidées par rapport au stock virtuel courant (voir
        // CartController::revalidateCartStock(), appelée systématiquement en
        // début de chaque étape du tunnel).
        $currentCartId = CartStorage::getOrCreateCartId();
        if ($persistedCartId !== null && $persistedCartId !== '') {
            CartStorage::restoreCartIfEmpty($currentCartId, $persistedCartId);
        }
    }

    /**
     * Charge le profil persisté d'un e-mail donné (fichier JSON) et le
     * fusionne en session, INDÉPENDAMMENT de tout cookie "Se souvenir de
     * moi" — utilisée après une identification réussie via OTP sur `/identify`
     * (voir {@see \Grav\Plugin\SimpleShop\Controllers\CustomerController::handleVerifyOtp()}),
     * où la session ne contient jusqu'ici que l'e-mail saisi.
     *
     * CORRECTIF (bug) : auparavant, `/identify` ne rechargeait jamais le
     * profil stocké avant d'appeler `handleDataPersistence()`, ce qui
     * écrasait silencieusement un profil complet existant (la session ne
     * contenant que l'e-mail, la branche "keep-data absent" de
     * `handleDataPersistence()` déclenchait `clearUserData()` sur le fichier
     * existant). Cette méthode répare ce cas en rechargeant explicitement le
     * profil stocké AVANT toute nouvelle persistance.
     *
     * Ne restaure un profil complet QUE s'il est "significatif" (voir
     * {@see self::isProfileDataMeaningful()}). Si le fichier existe mais est
     * "cleared" (email + `_persist` seulement) — ou n'existe pas du tout —
     * les informations `_persist` (ex. newsletter) sont quand même
     * synchronisées en session si disponibles, pour un affichage cohérent du
     * formulaire « Mon compte », mais la méthode retourne `false` (aucun
     * profil complet à afficher).
     *
     * @param string $email Adresse e-mail dont le profil doit être chargé.
     *
     * @return bool Vrai si un profil complet ("significatif") a été restauré
     *              en session ; faux si le fichier est absent ou "cleared".
     */
    public function loadPersistedProfileForEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }

        $filePath = $this->userFilePathForEmail($email);

        if (!file_exists($filePath)) {
            return false;
        }

        $jsonContent = @file_get_contents($filePath);
        $customerData = @json_decode($jsonContent, true);

        if (!is_array($customerData)) {
            return false;
        }

        if (!$this->isProfileDataMeaningful($customerData)) {
            // Fichier "cleared" : pas de profil complet à restaurer, mais on
            // synchronise au moins _persist (newsletter...) pour la cohérence
            // d'affichage du formulaire « Mon compte ».
            if (!empty($customerData['_persist']) && is_array($customerData['_persist'])) {
                $this->setCustomerData(['_persist' => $customerData['_persist']]);
            }

            return false;
        }

        $this->mergeStoredProfileIntoSession($customerData);

        return true;
    }

    /**
     * Tente une connexion automatique via le cookie persistant (Rolling Token).
     *
     * Vérifie le statut "banni" ({@see self::isEmailBanned()}, registre en
     * clair) dès que l'e-mail associé au cookie est connu (le cookie
     * lui-même ne contient qu'un hash) : un compte banni ne doit jamais
     * pouvoir se reconnecter automatiquement, même avec un jeton par ailleurs
     * valide.
     *
     * La fusion du profil en session et la restauration conditionnelle du
     * panier sont déléguées à {@see self::mergeStoredProfileIntoSession()},
     * partagée avec {@see self::loadPersistedProfileForEmail()} (flux
     * `/identify`, sans cookie).
     */
    public function attemptAutoLogin(): bool
    {
        if (empty($_COOKIE['simple_shop_remember'])) {
            return false;
        }

        $parts = explode(':', $_COOKIE['simple_shop_remember'], 2);
        if (count($parts) !== 2) {
            $this->clearRememberCookie();
            return false;
        }

        [$emailHash, $token] = $parts;
        if (!preg_match('/^[a-f0-9]{64}$/', $emailHash)) {
            $this->clearRememberCookie();
            return false;
        }

        $storageDir = $this->grav['locator']->findResource('user://data/simple-shop/users', true, true);
        $filePath = $storageDir . '/' . $emailHash . '.json';

        if (!file_exists($filePath)) {
            $this->clearRememberCookie();
            return false;
        }

        $jsonContent = @file_get_contents($filePath);
        $customerData = @json_decode($jsonContent, true);

        if (!is_array($customerData)) {
            $this->clearRememberCookie();
            return false;
        }

        $email = trim((string) ($customerData['email'] ?? ''));

        // Un compte banni ne doit jamais pouvoir se reconnecter
        // automatiquement, même avec un jeton "Se souvenir de moi" par
        // ailleurs valide. Le registre de bannissement ne travaille qu'avec
        // des adresses en clair (jamais le hash utilisé par le cookie).
        if ($email !== '' && $this->isEmailBanned($email)) {
            $this->clearRememberCookie();
            return false;
        }

        if (empty($customerData['persistent_token']) || !password_verify($token, $customerData['persistent_token'])) {
            $this->clearRememberCookie();
            return false;
        }

        // Auto-login réussi : fusion du profil en session (voir méthode
        // partagée) et restauration conditionnelle du panier.
        $this->mergeStoredProfileIntoSession($customerData);

        // Rotation du token (Rolling Token)
        $newPlainToken = bin2hex(random_bytes(32));
        $customerDataToStore = $this->getCustomerData();
        $currentCartId = CartStorage::getOrCreateCartId();
        $customerDataToStore['persistent_token'] = password_hash($newPlainToken, PASSWORD_DEFAULT);
        $customerDataToStore['updated_at'] = time();
        $customerDataToStore['cart_id'] = $currentCartId;

        @file_put_contents($filePath, json_encode($customerDataToStore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        setcookie(
            'simple_shop_remember',
            $emailHash . ':' . $newPlainToken,
            [
                'expires' => time() + (86400 * 30),
                'path' => '/',
                'secure' => $this->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );

        return true;
    }

    /**
     * Déconnecte le client et supprime le cookie "Se souvenir de moi".
     *
     * IMPORTANT : cette méthode NE SUPPRIME PLUS le fichier de persistance
     * utilisateur. Une déconnexion volontaire ne doit pas détruire les
     * informations `_persist` gérées côté admin (newsletter, `groups`...),
     * qui doivent survivre indépendamment de la session/cookie courants.
     */
    public function logout(): void
    {
        $this->clearRememberCookie();

        unset($this->session->shop_order);
    }
    
    /**
     * Sauvegarde ou supprime les données client selon l'option "keep-data".
     *
     * Si "keep-data" est cochée, le panier courant (`session->shop_order->cart_id`)
     * est également mémorisé dans le fichier (clé top-level `cart_id`, hors
     * `_persist`) afin de pouvoir être restauré lors d'une future connexion
     * automatique (voir {@see self::attemptAutoLogin()} et
     * {@see CartStorage::restoreCartIfEmpty()}).
     */
    public function handleDataPersistence(): void
    {
        $customerData = $this->getCustomerData();
        $email = $customerData['email'] ?? null;
        $keepData = !empty($customerData['keep-data']);

        if (!$email) {
            return;
        }

        $emailHash = hash('sha256', strtolower(trim($email)));
        $storageDir = $this->grav['locator']->findResource('user://data/simple-shop/users', true, true);
        
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        
        $filePath = $storageDir . '/' . $emailHash . '.json';

        if ($keepData) {
            // Génération d'un token persistant (Rolling Token)
            $persistentToken = bin2hex(random_bytes(32));
            $customerData['persistent_token'] = password_hash($persistentToken, PASSWORD_DEFAULT);
            $customerData['updated_at'] = time();

            // Mémorisation du panier courant (voir doc de méthode ci-dessus).
            $customerData['cart_id'] = $this->session->shop_order->cart_id ?? null;

            // Sauvegarde dans le fichier JSON isolé
            file_put_contents($filePath, json_encode($customerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Pose du cookie sécurisé
            $cookieValue = $emailHash . ':' . $persistentToken;
            setcookie(
                'simple_shop_remember',
                $cookieValue,
                [
                    'expires' => time() + (86400 * 30), // 30 jours
                    'path' => '/',
                    'secure' => $this->isHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        } else {
            // Si l'utilisateur a décoché l'option, on supprime les données stockées
            // (sauf _persist, voir clearUserData()).
            if (file_exists($filePath)) {
                $this->clearUserData($filePath);
            }
            // Suppression du cookie
            if (isset($_COOKIE['simple_shop_remember'])) {
                setcookie('simple_shop_remember', '', time() - 3600, '/', '', $this->isHttps(), true);
            }
        }
    }

    /**
     * Supprime les données de profil d'un fichier client tout en préservant
     * l'adresse e-mail (clé `email`) et les informations `_persist`
     * (newsletter, et à terme `groups`...).
     *
     * Utilisée dans deux contextes :
     *  - {@see self::handleDataPersistence()} : lorsque le client décoche
     *    lui-même "keep-data" ;
     *  - {@see self::ban()} : lorsqu'un client est banni côté admin. Un
     *    compte banni ne pouvant plus se connecter (voir
     *    {@see self::isEmailBanned()}), il ne peut plus passer par la page
     *    « Modifier mes informations » pour effacer son profil lui-même. Le
     *    bannissement déclenche donc automatiquement le même effacement que
     *    s'il avait décoché "keep-data" (analyse RGPD, voir note de
     *    {@see self::ban()}).
     *
     * `_persist` (newsletter, groups...) ne dépend pas de ce consentement de
     * conservation de profil mais d'une gestion admin séparée : le préserver
     * évite de perdre une preuve de désinscription newsletter déjà
     * enregistrée (recommandation CNIL de conservation d'une liste de
     * suppression), et permet de conserver `groups` (remises
     * partenaires/clients réguliers) indépendamment de tout aléa de
     * connexion ou de bannissement.
     *
     * ⚠️ Compromis assumé : conserver l'e-mail en clair dans ce stub réduit
     * légèrement la pseudonymisation "par obscurité" du fichier (avant ce
     * choix, retrouver l'e-mail à partir d'un stub isolé nécessitait de
     * connaître déjà l'adresse pour vérifier le hash). Toutes les autres
     * données de profil (nom, adresse, téléphone, jeton persistant...)
     * restent supprimées.
     *
     * @param string $filePath Chemin du fichier JSON client à traiter.
     */
    private function clearUserData(string $filePath): void
    {
        $jsonContent = @file_get_contents($filePath);
        $customerData = @json_decode($jsonContent, true);

        if (!is_array($customerData) || empty($customerData['_persist'])) {
            @unlink($filePath);
        } else {
            $customerData = [
                'email'    => $customerData['email'] ?? null,
                '_persist' => $customerData['_persist'],
            ];
            file_put_contents($filePath, json_encode($customerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Charge les informations `_persist` (newsletter, et à terme `groups`...)
     * depuis le fichier de stockage correspondant à un e-mail donné.
     *
     * Ne lit JAMAIS la session : c'est le fichier qui reste la source de
     * vérité pour ces informations.
     *
     * @param string $email Adresse e-mail concernée.
     *
     * @return array Le tableau `_persist` (vide si absent/fichier introuvable).
     */
    private function loadPersistDataForEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        $filePath = $this->userFilePathForEmail($email);

        if (!file_exists($filePath)) {
            return [];
        }

        $jsonContent = @file_get_contents($filePath);
        $decoded = @json_decode($jsonContent, true);

        if (!is_array($decoded) || empty($decoded['_persist']) || !is_array($decoded['_persist'])) {
            return [];
        }

        return $decoded['_persist'];
    }

    /**
     * Désabonne une adresse e-mail de la newsletter (`_persist.newsletter =
     * false`), indépendamment de toute session ou identification préalable
     * (utilisé par un lien de désabonnement cliqué depuis un e-mail).
     *
     * Crée le fichier de stockage s'il n'existait pas encore (cas d'un e-mail
     * jamais identifié sur le site mais présent dans une liste newsletter
     * externe, ou cas d'un compte banni dont le profil a été purgé par
     * {@see self::ban()}) ; dans ce cas, seul `_persist.newsletter` est
     * renseigné, sans aucune autre donnée personnelle.
     *
     * Si l'e-mail correspond au client actuellement en session, son état est
     * également synchronisé en session pour un retour visuel immédiat (page
     * « Mon compte »), sans nécessiter de nouvelle identification.
     *
     * NOTE : ne vérifie volontairement PAS le statut banni : un compte banni
     * doit pouvoir se désabonner de la newsletter. C'est d'ailleurs le SEUL
     * canal d'auto-service RGPD qui lui reste accessible, puisqu'il ne peut
     * plus se connecter pour passer par « Modifier mes informations » (voir
     * {@see self::ban()}).
     *
     * @param string $email Adresse e-mail à désabonner.
     *
     * @return bool Vrai si le traitement a pu être effectué (e-mail valide).
     */
    public function unsubscribeNewsletter(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $emailHash = hash('sha256', strtolower($email));
        $storageDir = $this->grav['locator']->findResource('user://data/simple-shop/users', true, true);

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $filePath = $storageDir . '/' . $emailHash . '.json';

        $customerData = [];
        if (file_exists($filePath)) {
            $jsonContent = @file_get_contents($filePath);
            $decoded = @json_decode($jsonContent, true);
            if (is_array($decoded)) {
                $customerData = $decoded;
            }
        }

        $customerData['_persist'] = (array) ($customerData['_persist'] ?? []);
        $customerData['_persist']['newsletter'] = false;

        file_put_contents($filePath, json_encode($customerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Synchronisation immédiate en session si le client courant correspond.
        $currentEmail = trim((string) ($this->getCustomerData()['email'] ?? ''));
        if ($currentEmail !== '' && strcasecmp($currentEmail, $email) === 0) {
            $persist = (array) ($this->getCustomerData()['_persist'] ?? []);
            $persist['newsletter'] = false;
            $this->setCustomerData(['_persist' => $persist]);
        }

        return true;
    }

    /**
     * Normalise une adresse e-mail pour les besoins du registre de
     * bannissement (`trim` + minuscules).
     *
     * @param string $email Adresse e-mail à normaliser.
     *
     * @return string L'adresse normalisée (chaîne vide si invalide/vide).
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Calcule (et crée si besoin) le chemin du registre des adresses
     * bannies : `user://data/simple-shop/banned.list`.
     *
     * @return string Le chemin absolu du fichier registre.
     */
    private function bannedListPath(): string
    {
        $storageDir = $this->grav['locator']->findResource('user://data/simple-shop', true, true);

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        return $storageDir . '/' . self::BANNED_LIST_FILENAME;
    }

    /**
     * Lit le registre des adresses bannies.
     *
     * @return array<string, true> Un ensemble (clé = e-mail normalisé) pour
     *                              une recherche en O(1).
     */
    private function readBannedList(): array
    {
        $path = $this->bannedListPath();

        if (!file_exists($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $emails = [];
        foreach ($lines as $line) {
            $normalized = $this->normalizeEmail($line);
            if ($normalized !== '') {
                $emails[$normalized] = true;
            }
        }

        return $emails;
    }

    /**
     * Réécrit intégralement le registre des adresses bannies (triées, une
     * par ligne), avec verrouillage exclusif pendant l'écriture.
     *
     * @param array<string, true> $emails Ensemble des e-mails bannis (déjà normalisés).
     *
     * @return void
     */
    private function writeBannedList(array $emails): void
    {
        $path = $this->bannedListPath();

        $lines = array_keys($emails);
        sort($lines);

        $content = $lines ? (implode("\n", $lines) . "\n") : '';

        file_put_contents($path, $content, LOCK_EX);
    }

    /**
     * Vérifie si une adresse e-mail donnée est bannie, en lisant directement
     * le registre en clair (`banned.list`) — jamais `_persist`.
     *
     * À utiliser à chaque point d'entrée sensible : demande d'OTP, validation
     * du code, sauvegarde d'un formulaire d'identité (voir
     * {@see self::handleUserData()}), tunnel d'achat, auto-login.
     *
     * @param string $email Adresse e-mail à vérifier.
     *
     * @return bool Vrai si l'adresse est bannie.
     */
    public function isEmailBanned(string $email): bool
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return false;
        }

        return isset($this->readBannedList()[$email]);
    }

    /**
     * Retourne la liste (triée) de toutes les adresses actuellement bannies.
     *
     * Utilitaire prévu pour une future interface d'administration listant
     * les bannissements ; non câblé à ce jour dans l'admin.
     *
     * @return string[] Les adresses e-mail bannies, normalisées.
     */
    public function getBannedEmails(): array
    {
        return array_keys($this->readBannedList());
    }

    /**
     * Bannit une adresse e-mail : l'ajoute au registre `banned.list` si elle
     * n'y figure pas déjà, puis purge automatiquement son profil persisté
     * (voir {@see self::clearUserData()}).
     *
     * NOTE RGPD : un compte banni ne pouvant plus s'identifier (OTP et
     * auto-login bloqués par {@see self::isEmailBanned()}), il perd l'accès
     * à la page « Modifier mes informations » et ne peut donc plus exercer
     * lui-même son droit à l'effacement du profil. Le bannissement déclenche
     * donc le même traitement que s'il avait lui-même décoché "keep-data" :
     * suppression du nom, de l'adresse, du téléphone et du jeton persistant,
     * en conservant uniquement `email` et `_persist` (newsletter, groups...).
     * Cette conservation partielle est volontaire : supprimer `_persist`
     * effacerait aussi une éventuelle preuve de désinscription newsletter
     * déjà enregistrée (la CNIL recommande de conserver ce type de "liste de
     * suppression"). Le lien de désabonnement newsletter par e-mail (HMAC,
     * indépendant de la session) reste quant à lui le seul canal
     * d'auto-service qui demeure accessible à un compte banni.
     *
     * @param string $email Adresse e-mail à bannir.
     *
     * @return bool Vrai si l'adresse est valide et a été traitée (bannie,
     *              qu'elle l'ait déjà été ou non).
     */
    public function ban(string $email): bool
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $list = $this->readBannedList();
        if (!isset($list[$normalized])) {
            $list[$normalized] = true;
            $this->writeBannedList($list);
        }

        $filePath = $this->userFilePathForEmail($normalized);

        if (file_exists($filePath)) {
            $this->clearUserData($filePath);
        }

        return true;
    }

    /**
     * Débannit une adresse e-mail : la retire du registre `banned.list` si
     * elle y figure.
     *
     * NOTE : ne restaure PAS le profil éventuellement purgé lors du
     * bannissement ({@see self::ban()}) — cette purge est définitive, le
     * client devra ressaisir ses informations lors de sa prochaine commande
     * ou visite de « Mon compte ». Seuls `email` et `_persist` (newsletter,
     * groups...) auront survécu, le cas échéant.
     *
     * @param string $email Adresse e-mail à débannir.
     *
     * @return bool Vrai si l'adresse figurait dans le registre et en a été
     *              retirée ; faux si elle n'y figurait pas (ou e-mail invalide).
     */
    public function unban(string $email): bool
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === '') {
            return false;
        }

        $list = $this->readBannedList();
        if (isset($list[$normalized])) {
            unset($list[$normalized]);
            $this->writeBannedList($list);

            return true;
        }

        return false;
    }
}
