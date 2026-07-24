<?php
/**
 * Pipeline Editor — front controller (on the Sidecar Kit).
 * Hard allowlist gate before any app code; then the shared Kernel boots and
 * dispatches to the plugin's own controllers.
 */

if (php_sapi_name() === 'cli-server') {
    $f = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($f)) return false;   // dev static passthrough
}

$cfg      = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
$coreRoot = rtrim($cfg['sidecar']['core_root'] ?? '/var/www/html/default/tiknix', '/');

require $coreRoot . '/lib/Sidecar/Kernel.php';

app\Sidecar\Kernel::guard(['', 'sso', 'edit', 'index', 'error']);

(new app\Sidecar\Kernel(dirname(__DIR__), [
    'index' => 'Index',
    'sso'   => 'Sso',
    'edit'  => 'Edit',
]))->run();
