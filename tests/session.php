<?php
// Session : le renouvellement d'identifiant (protection contre la fixation de session) garde les données ; la
// commande en session est créée si absente. Régression du 19/10/2026 : avec un cookie « Se souvenir de moi », chaque
// page renouvelait la session en la vidant (Grav 2) — identification impossible, messages jamais affichés.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/session.php
require __DIR__ . '/support/bootstrap.php';

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();

$grav = \SimpleShopTest\boot([], [], null);
$m = $grav['simple_shop_customer'];

// 1. Session vide (juste après un renouvellement) : écrire le profil crée la commande en session.
$_SESSION = [];
$m->setCustomerData(['email' => 'client@example.com', 'firstname' => 'Jean']);
assert(($m->getCustomerData()['email'] ?? '') === 'client@example.com', 'profil écrit dans une session vide');

// 2. Vérification par code : la session est renouvelée, les données restent (profil, vérification, messages, panier).
$_SESSION['public_messages'] = [['message' => 'Bonjour', 'scope' => 'info']];
$_SESSION['shop_order']->cart_id = 'panier-1';
$code = $m->getOrGenerateEmailVerificationCode('client@example.com');
assert($m->verifyEmailCode($code), 'code accepté');
assert($m->isEmailVerified(), 'e-mail vérifié après renouvellement');
assert(($m->getCustomerData()['firstname'] ?? '') === 'Jean', 'profil conservé');
assert(($_SESSION['shop_order']->cart_id ?? '') === 'panier-1', 'panier conservé');
assert(count($_SESSION['public_messages'] ?? []) === 1, 'messages conservés');
assert(!isset($_SESSION['session_destroyed']) && !isset($_SESSION['session_new_id']), 'marqueurs de l\'ancienne session non recopiés');

// 3. Déconnexion : commande retirée, rien d'autre de perdu.
$m->logout();
assert(!isset($_SESSION['shop_order']) && count($_SESSION['public_messages'] ?? []) === 1, 'déconnexion');

echo "OK\n";
