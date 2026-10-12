<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureRequest.php';

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';
$path = \Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']);
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$a = 'Bearer ' . \Tablo\Tests\Support\EndpointFixture::tokenA();
$b = 'Bearer ' . \Tablo\Tests\Support\EndpointFixture::tokenB();
$row = ['path' => $path, 'present' => $authorization !== '', 'a' => hash_equals($a, $authorization),
    'b' => hash_equals($b, $authorization), 'git' => str_starts_with($path, '/repos/') || str_starts_with($path, '/search/')];
file_put_contents(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_ENDPOINT_FIXTURE')) . '/requests.jsonl', json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
if ($row['git']) { require __DIR__ . '/worker-router.php'; return; }
if ($path === '/barrier/') {
    file_put_contents(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_ENDPOINT_FIXTURE')) . '/entered', data: 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file(\Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_ENDPOINT_FIXTURE')) . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
}
$match = [];
$homeStatus = preg_match('~^/home/(\d{3})/+$~D', $path, $match) === 1 ? (int) \Tablo\Tests\Support\FixtureValue::at($match, 1) : 200;
$status = (int) ($_GET['status'] ?? $homeStatus);
http_response_code($status);
if (in_array($status, [301, 302], strict: true)) { header('Location: /destination'); }
switch (true) {
case (str_contains($path, 'version')):

    header('Content-Type: application/json');
    if (array_key_exists('reflect', $_GET)) {
        echo json_encode(['version' => substr($authorization, strlen('Bearer '))], JSON_THROW_ON_ERROR);
        return;
    }
    echo (($_GET['bad'] ?? null) !== null) ? $authorization : '{"version":"1.0.0","commit":"abcdef1"}';

    break;
case (str_contains($path, 'json')):

    header('Content-Type: application/json');
    echo '{"result":"ok","number":3}';

    break;
case ($path === '/large'):

    echo str_repeat(chr(90), times: 1_048_577);

    break;
default:

    header('Content-Type: text/html');
    echo '<html>Endpoint fixture</html>';

    break;
}
