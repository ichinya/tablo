<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureValue.php';
require_once __DIR__ . '/../Support/FixtureRequest.php';

// Count real requests without storing request credentials or response bodies.
$path = \Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']);
$now = (int) getenv('TABLO_TEST_RESPONSE_NOW');
$status = str_contains($path, '429') ? 429 : (match (true) { (str_contains($path, 'control')) => 200, default => 403 });
file_put_contents(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_TEST_RESPONSE_COUNT')), $status . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($status !== 200) { header('Location: /repos/fixture/control/releases/latest'); }
http_response_code($status);
if ($status === 200) { echo '{"tag_name":"v1"}'; return; }
switch (true) {
case (str_contains($path, 'primary')):

    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Resource: core');
    header('X-RateLimit-Reset: ' . ($now + 7200));

    break;
case (str_contains($path, 'retry')):

    header('Retry-After: 3600');
    header('X-RateLimit-Reset: ' . ($now + 7200));

    break;
}
echo json_encode([
    'message' => str_contains($path, 'secondary') ? 'You have exceeded a secondary rate limit' : 'opaque upstream error',
    'private' => getenv('TABLO_TEST_RESPONSE_MARKER'),
    'echo' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
], JSON_THROW_ON_ERROR);
