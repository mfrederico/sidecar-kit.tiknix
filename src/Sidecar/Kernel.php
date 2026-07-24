<?php
/**
 * Sidecar\Kernel — the shared bootstrap for every sidecar plugin (explorer, store,
 * future integrations). A plugin's public/index.php is just:
 *
 *     require '<core>/lib/Sidecar/Kernel.php';   // or via the sidecar autoloader
 *     app\Sidecar\Kernel::guard(['', 'sso', 'shop', 'index', 'error']);   // allowlist
 *     (new app\Sidecar\Kernel(dirname(__DIR__), ['index'=>'Index','sso'=>'Sso',...]))->run();
 *
 * It: reuses core's vendor + shared classes via a sidecar-FIRST autoloader (the
 * precedence fix — Composer prepends itself, so ours must register AFTER it), opens
 * its own sqlite (sessions/nonces/plugin data) plus a read-only handle to core,
 * starts a session, and registers an allowlisted catch-all route to the plugin's
 * OWN controllers (never core's defaultRoute, whose security check rejects foreign
 * controls/). `build` is forced false so no route ever auto-provisions permissions.
 *
 * Config contract — the plugin's conf/config.ini has a `[sidecar]` section:
 *   name, feature, core_root, core_url, sso_secret
 * plus the usual [app] (baseurl, session_name) and [database] (own sqlite).
 */

namespace app\Sidecar;

use \Flight as Flight;
use RedBeanPHP\R;

class Kernel {

    private array $config;
    private string $coreRoot;

    /**
     * @param string $root     the plugin app root (dirname of public/)
     * @param array  $routeMap first-url-segment => controller class (in app\ namespace)
     * @param string $configFile relative to $root
     */
    public function __construct(private string $root, private array $routeMap, string $configFile = 'conf/config.ini') {
        $this->config   = @parse_ini_file($this->root . '/' . $configFile, true) ?: [];
        $this->coreRoot = rtrim((string) ($this->config['sidecar']['core_root'] ?? '/var/www/html/default/tiknix'), '/');

        // ORDER MATTERS: Composer registers with prepend=true, so require it FIRST,
        // then prepend ours — ours then wins for plugin classes (app\Index, …) while
        // core classes (BaseControls\Control, Bean, …) fall through.
        require $this->coreRoot . '/vendor/autoload.php';
        $this->registerAutoloader();
        require_once $this->coreRoot . '/lib/FlightMap.php';   // LEVELS, CLASS_NAMESPACE, Flight maps
        require_once $this->coreRoot . '/lib/functions.php';   // is_control_plane(), h(), …

        $this->flattenConfig();
        $this->connectDb();
        $this->startSession();

        Flight::set('flight.views.path', $this->root . '/views');
        Flight::set('build', false);
        Flight::set('sidecar.root', $this->root);
        Flight::set('sidecar.core_root', $this->coreRoot);
        $this->installLogger();
        $this->registerRoutes();
    }

    public function run(): void { Flight::start(); }

    /** The plugin name (aud + feature key namespace), from [sidecar] name. */
    public static function name(): string { return (string) (Flight::get('sidecar.name') ?? 'sidecar'); }

    /**
     * HARD ALLOWLIST GATE — call from public/index.php BEFORE the Kernel loads. This
     * framework defaults an undefined route to PUBLIC, so on a core-reusing clone
     * deny must be ACTIVE. 403s any first path segment not in $allow, pre-dispatch.
     */
    public static function guard(array $allow): void {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $seg  = strtolower(explode('/', trim($path, '/'))[0] ?? '');
        if (!in_array($seg, $allow, true)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            exit("Not available on this sidecar.\n");
        }
    }

    /** Read-only PDO handle to CORE's SQLite db (identity + ownership scoping). */
    public static function coreDb(): ?\PDO {
        $root = rtrim((string) (Flight::get('sidecar.core_root') ?: ''), '/');
        $ini  = @parse_ini_file("$root/conf/config.ini", true) ?: [];
        $path = $ini['database']['path'] ?? '';
        if (($ini['database']['type'] ?? '') !== 'sqlite' || $path === '') return null;
        $abs = $path[0] === '/' ? $path : "$root/$path";
        if (!is_file($abs)) return null;
        try {
            $pdo = new \PDO('sqlite:' . $abs);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            return $pdo;   // read-only by convention: a sidecar never writes core
        } catch (\Throwable $e) { return null; }
    }

    // --- internals ----------------------------------------------------------

    /** app\* classes resolve from the PLUGIN tree first; unresolved fall to core. */
    private function registerAutoloader(): void {
        $root = $this->root;
        spl_autoload_register(function (string $class) use ($root): void {
            if (strncmp($class, 'app\\', 4) !== 0) return;
            foreach (self::candidatePaths($class) as $rel) {
                $f = "$root/$rel";
                if (is_file($f)) { require $f; return; }
            }
        }, true, true);   // prepend so the plugin wins over composer's core PSR-4
    }

    private static function candidatePaths(string $class): array {
        if (strncmp($class, 'app\\mcptools\\', 13) === 0)
            return ['mcptools/' . str_replace('\\', '/', substr($class, 13)) . '.php'];
        if (strncmp($class, 'app\\BaseControls\\', 17) === 0)
            return ['controls/BaseControls/' . str_replace('\\', '/', substr($class, 17)) . '.php'];
        if (strncmp($class, 'app\\services\\', 13) === 0)
            return ['services/' . str_replace('\\', '/', substr($class, 13)) . '.php'];
        $tail = str_replace('\\', '/', substr($class, 4)) . '.php';
        return ["controls/$tail", "lib/$tail"];
    }

    private function flattenConfig(): void {
        foreach ($this->config as $section => $values) {
            if (is_array($values)) foreach ($values as $k => $v) Flight::set("{$section}.{$k}", $v);
            else Flight::set($section, $values);
        }
    }

    private function connectDb(): void {
        $db  = $this->config['database']['path'] ?? 'data/sidecar.db';
        $abs = $db[0] === '/' ? $db : $this->root . '/' . $db;
        @mkdir(dirname($abs), 0775, true);
        if (!R::hasDatabase('default')) {
            R::setup('sqlite:' . $abs);
            R::freeze(false);   // fluid: auto-create the plugin's own tables
        }
    }

    private function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name((string) ($this->config['app']['session_name'] ?? 'SIDECAR_SESSION'));
            // A plugin that spans subdomains (e.g. <store>.shop.tiknix.com) sets
            // [app] cookie_domain = .shop.tiknix.com so ONE SSO session covers them
            // all. Only apply it when the REQUEST host actually belongs to that domain
            // — otherwise (localhost/dev, an unrelated host) fall back to a host-only
            // cookie so sessions still work.
            $cookieDomain = (string) ($this->config['app']['cookie_domain'] ?? '');
            $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $bare = ltrim($cookieDomain, '.');
            if ($cookieDomain !== '' && $host !== '' && ($host === $bare || str_ends_with($host, '.' . $bare))) {
                $secure = !str_starts_with((string) ($this->config['app']['baseurl'] ?? ''), 'http://');
                session_set_cookie_params([
                    'domain' => $cookieDomain, 'path' => '/', 'secure' => $secure,
                    'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
            session_start();
        }
    }

    /** No-op logger stub — core shared code calls Flight::get('log')->debug/error/…. */
    private function installLogger(): void {
        $name = self::name();
        Flight::set('log', new class($name) {
            public function __construct(private string $n) {}
            public function __call($m, $a) {
                if (in_array($m, ['error', 'critical', 'alert', 'emergency'], true)) {
                    error_log("[{$this->n}] " . (is_string($a[0] ?? null) ? $a[0] : json_encode($a[0] ?? null)));
                }
            }
        });
    }

    /**
     * Allowlisted catch-all in core's defaultRoute shape (proven to match), dispatching
     * ONLY to the plugin's own controllers via $routeMap. Defense-in-depth with guard().
     */
    private function registerRoutes(): void {
        $map = $this->routeMap;
        Flight::route('/(@class(/@method(/@op(/@opid(/.*?)))))',
            function ($class = null, $method = null, $op = null, $opid = null, $route = null) use ($map) {
                $class  = strtolower($class ?: 'index');
                $raw    = (string) ($method ?: 'index');
                $method = preg_replace('/[^a-z0-9]/i', '', $raw) ?: 'index';
                $c = $map[$class] ?? null;
                if (!$c) { Flight::notFound(); return; }
                $fq = 'app\\' . $c;
                if (!class_exists($fq)) { Flight::notFound(); return; }
                $inst   = new $fq();
                $params = ['operation' => (object) ['name' => $op, 'type' => $opid], 'route' => $route];
                if (method_exists($inst, 'setRouteParams')) $inst->setRouteParams($params);
                // Public method wins; else a controller may opt into catching unknown
                // sub-segments with _fallback (public storefront /shop/<slug>, etc.).
                if (method_exists($inst, $method) && (new \ReflectionMethod($inst, $method))->isPublic()) {
                    $inst->$method($params); return;
                }
                if (method_exists($inst, '_fallback')) { $inst->_fallback($raw, $params); return; }
                Flight::notFound();
            });
    }
}
