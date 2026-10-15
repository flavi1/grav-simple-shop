<?php
// Sécurité : secret des liens de désabonnement (jamais une valeur publique), quantité maximale d'une ligne.
// Lancement : YAML=<autoload symfony/yaml> php -d zend.assertions=1 -d assert.exception=1 tests/security.php
require __DIR__ . '/support/bootstrap.php';

use Grav\Plugin\SimpleShop\Controllers\CartController;
use Grav\Plugin\SimpleShop\Controllers\CustomerController;

$grav = \SimpleShopTest\boot(['newsletter_unsubscribe_secret' => ''], [], null);
$grav['config']->set('site.author.email', 'auteur@example.org');

$c = new CustomerController($grav);
$secret = (new ReflectionMethod($c, 'unsubscribeSecret'))->invoke($c);
assert(strlen($secret) >= 32, 'secret aléatoire');
assert($secret !== 'auteur@example.org' && $secret !== 'simple-shop-unsubscribe-secret', 'jamais une valeur publique');
assert((new ReflectionMethod($c, 'unsubscribeSecret'))->invoke(new CustomerController($grav)) === $secret, 'stable');
$grav['config']->set('plugins.simple-shop.newsletter_unsubscribe_secret', str_repeat('x', 20));
assert((new ReflectionMethod($c, 'unsubscribeSecret'))->invoke($c) === str_repeat('x', 20), 'secret configuré prioritaire');

assert(CartController::MAX_LINE_QUANTITY === 999);

echo "OK\n";
