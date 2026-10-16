<?php
// Titres et descriptions des pages de la boutique (Response du plugin mvc ≥ 0.2.0) et titre de repli tiré de la route.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/page-meta.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\Mvc\PageFactory;
use Grav\Plugin\Mvc\Request;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Config\PageMeta;
use Grav\Plugin\SimpleShop\Controllers\CartController;

$grav = \SimpleShopTest\boot([], [], null);

// 1. Panier vide : titre, description, pas d'indexation
$grav['uri']->current = '/cart';
$r = (new CartController($grav, new Request('GET', [], [], ['REQUEST_METHOD' => 'GET'])))->step1Cart();
assert($r->getTitle() === 'Votre panier', (string) $r->getTitle());
assert(str_starts_with($r->getMeta()['description'], 'Votre panier') && $r->getMeta()['robots'] === 'noindex, nofollow');

// 2. Surcharge par la configuration du site
$m = PageMeta::get('identify', ['pages' => ['identify' => ['title' => 'Se connecter']]]);
assert($m['title'] === 'Se connecter' && $m['description'] !== '', 'titre remplacé, description par défaut gardée');
assert(PageMeta::apply(new Response(), 'simple-shop/step3payment')->getTitle() === 'Paiement', 'gabarit → clé');

// 3. Repli : titre lisible tiré de la route (plus de « MVC - … »)
assert(PageFactory::titleFromRoute('/mon-compte/deconnexion') === 'Deconnexion');
assert(PageFactory::titleFromRoute('/checkout/payment-return') === 'Payment return');

echo "OK\n";
