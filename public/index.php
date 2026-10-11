<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return true; }
    throw new RuntimeException('Application initialization failed.');
});
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
