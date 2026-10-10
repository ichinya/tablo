<?php
declare(strict_types=1);

// Count real requests without storing request credentials or response bodies.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$now = (int) getenv('TABLO_TEST_RESPONSE_NOW');
$status = str_contains($path, '429') ? 429 : (str_contains($path, 'control') ? 200 : 403);
file_put_contents(getenv('TABLO_TEST_RESPONSE_COUNT'), $status . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($status !== 200) { header('Location: /repos/fixture/control/releases/latest'); }
http_response_code($status);
if ($status === 200) { echo '{"tag_name":"v1"}'; return; }
if (str_contains($path, 'primary')) {
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Resource: core');
    header('X-RateLimit-Reset: ' . ($now + 7200));
} elseif (str_contains($path, 'retry')) {
    header('Retry-After: 3600');
    header('X-RateLimit-Reset: ' . ($now + 7200));
}
echo json_encode([
    'message' => str_contains($path, 'secondary') ? 'You have exceeded a secondary rate limit' : 'opaque upstream error',
    'private' => getenv('TABLO_TEST_RESPONSE_MARKER'),
    'echo' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
], JSON_THROW_ON_ERROR);
