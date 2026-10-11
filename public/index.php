<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
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
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || !in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1', '127.0.0.1:80'], true)
        || $target !== '/_tablo/health'
        || (isset($_SERVER['THE_REQUEST']) && !preg_match('~^\S+ /_tablo/health HTTP/[0-9.]+$~D', $_SERVER['THE_REQUEST']))) {
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
    ob_start();
    try {
        require dirname(__DIR__) . '/vendor/autoload.php';
        $available = Tablo\ContainerHealth::available();
    } catch (Throwable) {
        // No exception paths, configuration or secrets in HTTP or health logs.
    } finally {
        $output = ob_get_clean();
    }
    $available = $available && $output === '';
    http_response_code($available ? 200 : 503);
    echo $available ? "ok\n" : "unavailable\n";
    return;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    (new Tablo\Web())->run();
} catch (Throwable $e) {
    error_log('Tablo initialization or operation failed. Check installation configuration and local storage.');
    if ($e instanceof Tablo\SharedKeyFailure && $e->getCode() === Tablo\SharedKeyFailure::UNVERIFIED) {
        error_log(Tablo\SharedKeyFailure::UNVERIFIED_DIAGNOSTIC);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Tablo — ошибка</title><h1>Не удалось открыть Tablo</h1><p>Подробности записаны в журнал сервера.</p></html>';
}
