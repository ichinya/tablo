<?php
declare(strict_types=1);
require_once __DIR__ . '/Support/FixtureRequest.php';
require_once __DIR__ . '/Support/FixtureValue.php';

$path = \Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']);
$fixture = getenv('TABLO_HEALTH_FIXTURE');
if ($fixture) {
    file_put_contents($fixture . '/uris.jsonl', \Tablo\Tests\Support\FixtureValue::string(json_encode($_SERVER['REQUEST_URI'])) . "\n", FILE_APPEND | LOCK_EX);
    if (str_starts_with($path, '/repos/') || str_starts_with($path, '/search/')) {
        require __DIR__ . '/fixtures/worker-router.php';
        return;
    }
    if ($path === '/barrier/') {
        file_put_contents($fixture . '/entered', data: 'ready');
        $deadline = microtime(true) + 8;
        while (!is_file($fixture . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
    }
    if (in_array($path, ['/', '/app/', '/app///', '/a//b///', '/%2Fapp/%7E///', '/barrier/'], strict: true)) {
        header('Content-Type: text/html');
        echo '<html>Exact homepage</html>';
        return;
    }
    $joined = [];
    if (preg_match('~^(/app|/a//b|/%2Fapp/%7E)/(up|json-health|version)$~D', $path, $joined)) {
        header('Content-Type: application/json');
        switch (true) {
case (\Tablo\Tests\Support\FixtureValue::at($joined, 2) === 'up'):
 http_response_code((int) ($_GET['status'] ?? 204)); echo '{}';
    break;
case (\Tablo\Tests\Support\FixtureValue::at($joined, 2) === 'json-health'):
 echo '{"result":"ok"}';
    break;
case (($_GET['bad'] ?? null) !== null):
 echo 'not JSON';
    break;
default:
 echo '{"version":"1.3.1","commit":"a61de82"}';
    break;
}
        return;
    }
}
$match = [];
if (preg_match('~^/homepage/(200|201|204|301|302|404|500|503)$~D', $path, $match)) {
    http_response_code((int) \Tablo\Tests\Support\FixtureValue::at($match, 1));
    header('Content-Type: text/html');
    if (in_array((int) \Tablo\Tests\Support\FixtureValue::at($match, 1), [301, 302], strict: true)) { header('Location: /up'); }
    echo '<html><body>Fixture homepage</body></html>';
    return;
}
switch ($path) {
    case '/redirect-health':
        header('Location: /up', replace: true, response_code: 301);
        break;
    case '/redirect-version':
        header('Location: /version', replace: true, response_code: 308);
        break;
    case '/redirect-missing':
        http_response_code(302);
        break;
    case '/rate-headers':
        http_response_code(429);
        header('rEtRy-AfTeR: 3600');
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Resource: search');
        header('X-RateLimit-Reset: 1900000000');
        header('Authorization: synthetic-secret');
        header('X-Arbitrary-Secret: synthetic-secret');
        echo '{}';
        break;
    case '/duplicate-headers':
        header('Retry-After: 1', replace: false);
        header('Retry-After: 9999', replace: false);
        header('X-RateLimit-Remaining: invalid');
        header('X-RateLimit-Resource: synthetic-secret');
        echo '{}';
        break;
    case '/short-delay':
        usleep(300_000);
        echo '{}';
        break;
    case '/up':
        header('Content-Type: application/json');
        if ($fixture && (($_GET['status'] ?? null) !== null)) { http_response_code((int) $_GET['status']); }
        echo '{"status":"ok"}';
        break;
    case '/version':
        header('Content-Type: application/json');
        echo '{"version":"1.3.1","commit":"a61de82"}';
        break;
    case '/json-health':
        header('Content-Type: application/json');
        echo '{"result":"ok","checks":{"passed":3}}';
        break;
    case '/json-version':
        header('Content-Type: application/json');
        echo '{"build":{"version":"1.2.3"},"sha":"abcdef1"}';
        break;
    case '/redirect':
        header('Location: /up', replace: true, response_code: 302);
        break;
    case '/large':
        echo str_repeat('x', times: 1_048_577);
        break;
    case '/slow':
        sleep(10);
        echo 'late';
        break;
    case '/truncated':
        header('Content-Length: 100');
        echo 'short';
        break;
    default:
        http_response_code(404);
}
