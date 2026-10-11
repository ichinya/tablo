<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$a = 'Bearer ' . \Tablo\Tests\Support\EndpointFixture::tokenA();
$b = 'Bearer ' . \Tablo\Tests\Support\EndpointFixture::tokenB();
$row = ['path' => $path, 'present' => $authorization !== '', 'a' => hash_equals($a, $authorization),
    'b' => hash_equals($b, $authorization), 'git' => str_starts_with($path, '/repos/') || str_starts_with($path, '/search/')];
file_put_contents(getenv('TABLO_ENDPOINT_FIXTURE') . '/requests.jsonl', json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
if ($row['git']) { require __DIR__ . '/worker-router.php'; return; }
if ($path === '/barrier/') {
    file_put_contents(getenv('TABLO_ENDPOINT_FIXTURE') . '/entered', 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file(getenv('TABLO_ENDPOINT_FIXTURE') . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
}
$homeStatus = preg_match('~^/home/(\d{3})/+$~D', $path, $match) === 1 ? (int) $match[1] : 200;
$status = (int) ($_GET['status'] ?? $homeStatus);
http_response_code($status);
if (in_array($status, [301, 302], true)) { header('Location: /destination'); }
if (str_contains($path, 'version')) {
    header('Content-Type: application/json');
    echo isset($_GET['bad']) ? $authorization : '{"version":"1.0.0","commit":"abcdef1"}';
} elseif (str_contains($path, 'json')) {
    header('Content-Type: application/json');
    echo '{"result":"ok","number":3}';
} elseif ($path === '/large') {
    echo str_repeat(chr(90), 1048577);
} else {
    header('Content-Type: text/html');
    echo '<html>Endpoint fixture</html>';
}
