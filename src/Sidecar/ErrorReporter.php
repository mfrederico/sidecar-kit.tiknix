<?php
/**
 * Sidecar\ErrorReporter — fire-and-forget error capture for the control-plane firehose,
 * ported from core's app\ErrorReporter so the kit is self-contained (no dependency on
 * core-class autoload resolution). Kernel::installFirehose() derives the ingest URL +
 * shared key from CORE's config and registers the fatal hook; the route dispatcher also
 * calls capture() for controller throws. Every capture ALSO writes to the file logger.
 *
 *   Layer 1 (here) — origin gate: report only when firehose.role = "live" and an
 *   ingest_url + api_key are configured. Rate-limited one POST per signature / 5 min.
 *   Layers 2 (active-build guard) + 3 (signature dedup) live on the ingest side.
 */

namespace app\Sidecar;

use \Flight as Flight;

class ErrorReporter
{
    const RATE_WINDOW = 300;                 // one POST per signature per 5 min
    const STATE_DIR   = '/tmp/tiknix-firehose';
    const TIMEOUT     = 2;                    // never stall the request on the reporter

    /** Catch true fatals (parse/OOM/timeout) that bypass the dispatcher try/catch. */
    public static function register(): void
    {
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        $err = error_get_last();
        if (!$err) return;
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($err['type'] & $fatal)) return;
        self::capture(
            new \ErrorException($err['message'], 0, $err['type'], $err['file'] ?? 'unknown', $err['line'] ?? 0),
            'php_fatal'
        );
    }

    /**
     * Capture a throwable: always log it to the sidecar's file logger, then (if enabled,
     * not rate-limited) POST it to core's firehose. Self-gating; never throws.
     */
    public static function capture(\Throwable $e, string $type = 'exception', array $context = []): void
    {
        try {
            $log = Flight::get('log');
            if (is_object($log) && method_exists($log, 'error')) {
                $log->error(get_class($e) . ': ' . self::firstLine($e->getMessage()), [
                    'type' => $type, 'file' => self::relFile($e->getFile()), 'line' => $e->getLine(),
                    'url'  => (string)($_SERVER['REQUEST_URI'] ?? ''),
                ]);
            }
            if (!self::enabled()) return;

            $tag = self::instanceTag();
            $sig = md5(implode('|', [$tag, $type, $e->getFile() . ':' . $e->getLine(), self::firstLine($e->getMessage())]));
            if (self::rateLimited($sig)) return;
            self::markSent($sig);

            self::post([
                'signature'    => $sig,
                'type'         => $type,
                'instance'     => $tag,
                'message'      => self::firstLine($e->getMessage()),
                'full_message' => mb_substr((string)$e->getMessage(), 0, 2000),
                'class'        => get_class($e),
                'file'         => self::relFile($e->getFile()),
                'line'         => (int)$e->getLine(),
                'trace'        => mb_substr($e->getTraceAsString(), 0, 4000),
                'url'          => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'http_method'  => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
                'context'      => $context,
            ]);
        } catch (\Throwable $inner) {
            // The reporter must never break the request it is reporting on.
        }
    }

    private static function enabled(): bool
    {
        if ((string)(Flight::get('firehose.ingest_url') ?? '') === '') return false;
        if ((string)(Flight::get('firehose.api_key') ?? '') === '') return false;
        if (!filter_var(Flight::get('firehose.report') ?? true, FILTER_VALIDATE_BOOLEAN)) return false;
        return ((string)(Flight::get('firehose.role') ?? 'live')) === 'live';
    }

    /** Sidecar identity for the firehose feed, e.g. "workbench.tiknix". */
    private static function instanceTag(): string
    {
        $tag = (string)(Flight::get('firehose.instance') ?? '');
        if ($tag !== '') return $tag;
        $base = (string)(Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? '');
        $host = parse_url($base, PHP_URL_HOST) ?: preg_replace('#^https?://#', '', $base);
        return preg_replace('/\.com$/', '', (string)$host);
    }

    private static function post(array $payload): void
    {
        $url = (string)Flight::get('firehose.ingest_url');
        $key = (string)(Flight::get('firehose.api_key') ?? '');
        if (!function_exists('curl_init')) return;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Firehose-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
        ]);
        curl_exec($ch);   // fire-and-forget; do NOT curl_close() (PHP 8.5 web-handler throw)
    }

    private static function rateLimited(string $sig): bool
    {
        $f = self::STATE_DIR . '/' . $sig;
        return is_file($f) && (time() - filemtime($f)) < self::RATE_WINDOW;
    }

    private static function markSent(string $sig): void
    {
        if (!is_dir(self::STATE_DIR)) @mkdir(self::STATE_DIR, 0770, true);
        @touch(self::STATE_DIR . '/' . $sig);
    }

    private static function firstLine(string $s): string
    {
        return mb_substr(trim((string)strtok($s, "\n")), 0, 300);
    }

    private static function relFile(string $f): string
    {
        $root = (string)(Flight::get('sidecar.root') ?? Flight::get('app.root') ?? '');
        return ($root !== '' && str_starts_with($f, $root)) ? ltrim(substr($f, strlen($root)), '/') : $f;
    }
}
