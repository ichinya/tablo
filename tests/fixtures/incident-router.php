<?php
declare(strict_types=1);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/up') {
    $root = getenv('TABLO_WORKER_FIXTURE');
    file_put_contents($root . '/health-requests', "up\n", FILE_APPEND | LOCK_EX);
    http_response_code((int) file_get_contents($root . '/health-status'));
    echo '{}';
    return;
}
require __DIR__ . '/worker-router.php';
