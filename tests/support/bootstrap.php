<?php
/**
 * Amorçage des tests en ligne de commande qui exercent les contrôleurs (tunnel d'achat) sans Grav.
 *
 * Fournit des doublures minimales de Grav (conteneur, Utils, Session, pages), du thème no-js (messages flash) et de
 * RocketTheme\Toolbox\Event\Event, plus les autoloaders des plugins mvc, simple-shop et simple-shop-payment-test.
 * Les données (SQLite, carriers.yaml, profils) vont dans un dossier temporaire recréé à chaque exécution.
 *
 * Variable d'environnement YAML : autoload de symfony/yaml (obligatoire ici : configuration et carriers.yaml).
 */

namespace Grav\Common {
    final class Grav implements \ArrayAccess
    {
        private static ?Grav $instance = null;
        private array $c = [];
        /** @var array<string, callable[]> */
        public array $listeners = [];

        public static function instance(): self { return self::$instance ??= new self(); }
        public static function reset(): void { self::$instance = null; }
        public function offsetExists($o): bool { return isset($this->c[$o]); }
        public function offsetGet($o): mixed { return $this->c[$o]; }
        public function offsetSet($o, $v): void { $this->c[$o] = $v; }
        public function offsetUnset($o): void { unset($this->c[$o]); }
        public function fireEvent($name, $event = null)
        {
            foreach ($this->listeners[$name] ?? [] as $listener) {
                $listener($event);
            }
            return $event;
        }
        public function redirect($url, $code = null): void { throw new \LogicException("Grav::redirect() appelé ({$url}) : un contrôleur doit retourner une Response."); }
    }

    final class Utils
    {
        public static function getNonce(string $action): string { return 'nonce-' . $action; }
        public static function verifyNonce($nonce, string $action): bool { return $nonce === 'nonce-' . $action; }
    }

    #[\AllowDynamicProperties]
    class Session
    {
        public function getId(): string { return 'test-session'; }
        public function isStarted(): bool { return true; }
    }
}

namespace Grav\Theme {
    class NoJs
    {
        public static array $messages = [];
        public static function addMessage(string $message, string $scope = 'info'): void { self::$messages[] = [$scope, $message]; }
    }
}

namespace RocketTheme\Toolbox\Event {
    class Event implements \ArrayAccess
    {
        protected array $items;
        public function __construct(array $items = []) { $this->items = $items; }
        public function offsetExists($o): bool { return isset($this->items[$o]); }
        public function offsetGet($o): mixed { return $this->items[$o] ?? null; }
        public function offsetSet($o, $v): void { $this->items[$o] = $v; }
        public function offsetUnset($o): void { unset($this->items[$o]); }
        public function toArray(): array { return $this->items; }
    }
}

namespace SimpleShopTest {
    use Grav\Common\Grav;

    /** Page produit en mémoire (en-tête = front matter). */
    final class FakePage
    {
        public function __construct(public string $route, public array $header, public string $template = 'book', public ?string $file = null) {}
        public function header() { return (object) $this->header; }
        public function title(): string { return (string) ($this->header['title'] ?? $this->route); }
        public function published(): bool { return ($this->header['published'] ?? true) !== false; }
        public function template(): string { return $this->template; }
        public function route(): string { return $this->route; }
        public function filePath(): ?string { return $this->file; }
        public function path(): ?string { return $this->file ? dirname($this->file) : null; }
    }

    final class FakePages
    {
        /** @var FakePage[] */
        public array $pages = [];
        public function init(): void {}
        public function dispatch($route) { return $this->pages[$route] ?? null; }
        public function all(): array { return array_values($this->pages); }
    }

    final class FakeConfig
    {
        public function __construct(public array $data) {}
        public function get(string $key, $default = null)
        {
            $node = $this->data;
            foreach (explode('.', $key) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    return $default;
                }
                $node = $node[$part];
            }
            return $node;
        }
        public function set(string $key, $value): void
        {
            $ref = &$this->data;
            foreach (explode('.', $key) as $part) {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref = $value;
        }
    }

    final class FakeLocator
    {
        public function __construct(private string $data, private string $plugins) {}
        public function findResource($uri, $absolute = true, $first = false)
        {
            foreach (['user://data/' => $this->data . '/', 'user://config/' => $this->data . '/config/', 'plugins://' => $this->plugins . '/'] as $prefix => $dir) {
                if (strpos($uri, $prefix) === 0) {
                    return $dir . substr($uri, strlen($prefix));
                }
            }
            if ($uri === 'user://data') {
                return $this->data;
            }
            return false;
        }
    }

    final class FakeLog
    {
        public array $lines = [];
        public function __call($level, $args) { $this->lines[] = [$level, (string) ($args[0] ?? '')]; }
    }

    final class FakeUri
    {
        public string $current = '/';
        public function route(): string { return $this->current; }
    }

    /**
     * Prépare un Grav de test : configuration du plugin (simple-shop.yaml + surcharges), pages produits,
     * carriers.yaml, passerelle de paiement de test, service client.
     */
    function boot(array $configOverrides, array $pages, ?string $carriersYaml): Grav
    {
        $plugins = realpath(__DIR__ . '/../../..');
        $data = sys_get_temp_dir() . '/simple-shop-tests-' . getmypid();
        if (is_dir($data)) {
            exec('rm -rf ' . escapeshellarg($data));
        }
        mkdir($data . '/config/plugins/simple-shop', 0777, true);
        mkdir($data . '/simple-shop', 0777, true);
        register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($data)));
        // Fiches produits : écrites dans le dossier temporaire (le déstockage modifie le fichier).
        foreach ($pages as $page) {
            if ($page->file === null) {
                $page->file = $data . '/pages' . $page->route . '/' . $page->template . '.md';
                @mkdir(dirname($page->file), 0777, true);
                file_put_contents($page->file, "---\n" . \Symfony\Component\Yaml\Yaml::dump($page->header) . "---\n");
            }
        }
        if ($carriersYaml !== null) {
            file_put_contents($data . '/config/plugins/simple-shop/carriers.yaml', $carriersYaml);
        }

        Grav::reset();
        $grav = Grav::instance();
        $config = \Symfony\Component\Yaml\Yaml::parseFile($plugins . '/simple-shop/simple-shop.yaml');
        $config = array_replace_recursive($config, $configOverrides);
        $grav['config'] = new FakeConfig(['plugins' => ['simple-shop' => $config], 'system' => []]);
        $grav['locator'] = new FakeLocator($data, $plugins);
        $grav['log'] = new FakeLog();
        $grav['session'] = new \Grav\Common\Session();
        $grav['uri'] = new FakeUri();
        $grav['twig'] = (object) ['twig_vars' => []];
        $grav['base_url_absolute'] = 'https://shop.test';
        $p = new FakePages();
        foreach ($pages as $page) {
            $p->pages[$page->route] = $page;
        }
        $grav['pages'] = $p;
        $grav['simple_shop_customer'] = new \Grav\Plugin\SimpleShop\Services\CustomerManager($grav['session'], $grav);
        $grav->listeners['onSimpleShopRegisterGateways'][] = function ($event) {
            $gateways = $event['gateways'] ?? [];
            $g = new \Grav\Plugin\SimpleShopPaymentTest\Gateways\PaymentGatewayTest();
            $gateways[$g->getId()] = $g;
            $event['gateways'] = $gateways;
        };

        return $grav;
    }
}

namespace {
    if (!getenv('YAML')) {
        fwrite(STDERR, "Variable YAML requise (autoload de symfony/yaml).\n");
        exit(2);
    }
    require getenv('YAML');
    $plugins = realpath(__DIR__ . '/../../..');
    if (is_file($plugins . '/simple-shop/vendor/autoload.php')) {
        require $plugins . '/simple-shop/vendor/autoload.php';   // Factur-X, FPDF/FPDI
    }
    spl_autoload_register(function ($c) use ($plugins) {
        $map = [
            'Grav\\Plugin\\SimpleShop\\'            => $plugins . '/simple-shop/classes/',
            'Grav\\Plugin\\Mvc\\'                   => $plugins . '/mvc/classes/',
            'Grav\\Plugin\\SimpleShopPaymentTest\\' => $plugins . '/simple-shop-payment-test/classes/',
        ];
        foreach ($map as $prefix => $dir) {
            if (strpos($c, $prefix) === 0) {
                $f = $dir . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
                if (is_file($f)) {
                    require $f;
                }
            }
        }
    });
}
