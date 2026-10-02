<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    (new Tablo\Web())->run();
} catch (Throwable $e) {
    error_log('Tablo: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Tablo — ошибка</title><h1>Не удалось открыть Tablo</h1><p>Подробности записаны в журнал сервера.</p></html>';
}
