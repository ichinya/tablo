<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureRequest.php';
require_once __DIR__ . '/../Support/FixtureValue.php';

if (\Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']) === '/up') {
    $root = \Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_WORKER_FIXTURE'));
    file_put_contents($root . '/health-requests', data: "up\n", flags: FILE_APPEND | LOCK_EX);
    http_response_code((int) file_get_contents($root . '/health-status'));
    echo '{}';
    return;
}
require __DIR__ . '/worker-router.php';
