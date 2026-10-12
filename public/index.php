<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

\Tablo\RuntimeIni::set('display_errors', value: '0');
\Tablo\RuntimeIni::set('log_errors', value: '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Application initialization failed.');
});
$target = $_SERVER['REQUEST_URI'] ?? '';
if ($target === '/_tablo/health' || str_starts_with($target, '/_tablo/health?') || str_starts_with($target, '/_tablo/health/')) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // Socket peer only: forwarded headers/trusted proxy configuration are irrelevant.
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], strict: true)
        || !in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1', '127.0.0.1:80'], strict: true)
        || $target !== '/_tablo/health'
        || ((($_SERVER['THE_REQUEST'] ?? null) !== null) && !preg_match('~^\S+ /_tablo/health HTTP/[0-9.]+$~D', $_SERVER['THE_REQUEST']))) {
        http_response_code(404);
        echo "not found\n";
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo "method not allowed\n";
        return;
    }
    $available = false;
    http_response_code(503);
    $headers = headers_list();
    $initialDepth = ob_get_level();
    $outputDetected = false;
    $closedEarly = false;
    $cleaning = false;
    $deferredResponse = false;
    // Suppress flushed chunks too; never consume a buffer belonging to the caller.
    ob_start(static function (string $chunk, int $phase) use (&$outputDetected, &$closedEarly, &$cleaning, &$deferredResponse): string {
        $outputDetected = $outputDetected || $chunk !== '';
        if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0) {
            $closedEarly = !$cleaning;
            return $deferredResponse ? "unavailable\n" : '';
        }
        return '';
    }, chunk_size: 4096);
    try {
        require dirname(__DIR__) . '/vendor/autoload.php';
        $available = Tablo\ContainerHealth::available();
    } catch (Throwable $discardedCleanup) {
        // No exception paths, configuration or secrets in HTTP or health logs.
     unset($discardedCleanup); }
    $cleaning = true;
    // Bound cleanup even if a dependency creates many or non-removable buffers.
    for ($discarded = 0; ob_get_level() > $initialDepth && $discarded < 32; ++$discarded) {
        $outputDetected = $outputDetected || ob_get_length() > 0;
        $status = ob_get_status();
        if (($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) { break; }
        try { if (!ob_end_clean()) { break; } }
        catch (Throwable) { $available = false; break; }
    }
    $available = $available && !$outputDetected && !$closedEarly
        && ob_get_level() === $initialDepth && headers_list() === $headers && http_response_code() === 503;
    // Apache transport headers are server-owned; remove only PHP's queued headers.
    // Already-sent headers / deliberate transport flush / process exit cannot be undone here.
    if (headers_sent()) { return; }
    header_remove();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code($available ? 200 : 503);
    if (ob_get_level() > $initialDepth) {
        // An intact sink emits the fixed failure at shutdown if nested cleanup was blocked.
        $deferredResponse = !$closedEarly;
        return;
    }
    echo $available ? "ok\n" : "unavailable\n";
    return;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    (new Tablo\Web())->run();
} catch (Throwable $e) {
    error_log('Tablo initialization or operation failed. Check installation configuration and local storage.');
    if ($e instanceof Tablo\InstallationException && $e->getCode() === Tablo\InstallationException::UNSUPPORTED_CURL) {
        error_log(Tablo\InstallationException::CURL_DIAGNOSTIC);
    }
    if ($e instanceof Tablo\SharedKeyFailure && $e->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        error_log(Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Tablo — ошибка</title><h1>Не удалось открыть Tablo</h1><p>Подробности записаны в журнал сервера.</p></html>';
}
