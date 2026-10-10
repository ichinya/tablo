<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fixture = getenv('TABLO_HEALTH_FIXTURE');
if ($fixture) {
    file_put_contents($fixture . '/uris.jsonl', json_encode($_SERVER['REQUEST_URI']) . "\n", FILE_APPEND | LOCK_EX);
    if (str_starts_with($path, '/repos/') || str_starts_with($path, '/search/')) {
        require __DIR__ . '/fixtures/worker-router.php';
        return;
    }
    if ($path === '/barrier/') {
        file_put_contents($fixture . '/entered', 'ready');
        $deadline = microtime(true) + 8;
        while (!is_file($fixture . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
    }
    if (in_array($path, ['/', '/app/', '/app///', '/a//b///', '/%2Fapp/%7e///', '/barrier/'], true)) {
        header('Content-Type: text/html');
        echo '<html>Exact homepage</html>';
        return;
    }
    if (preg_match('~^(/app|/a//b|/%2Fapp/%7e)/(up|json-health|version)$~D', $path, $joined)) {
        header('Content-Type: application/json');
        if ($joined[2] === 'up') { http_response_code((int) ($_GET['status'] ?? 204)); echo '{}'; }
        elseif ($joined[2] === 'json-health') { echo '{"result":"ok"}'; }
        elseif (isset($_GET['bad'])) { echo 'not JSON'; }
        else { echo '{"version":"1.3.1","commit":"a61de82"}'; }
        return;
    }
}
if (preg_match('~^/homepage/(200|201|204|301|302|404|500|503)$~D', $path, $match)) {
    http_response_code((int) $match[1]);
    header('Content-Type: text/html');
    if (in_array((int) $match[1], [301, 302], true)) { header('Location: /up'); }
    echo '<html><body>Fixture homepage</body></html>';
    return;
}
switch ($path) {
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
        header('Retry-After: 1', false);
        header('Retry-After: 9999', false);
        header('X-RateLimit-Remaining: invalid');
        header('X-RateLimit-Resource: synthetic-secret');
        echo '{}';
        break;
    case '/short-delay':
        usleep(300000);
        echo '{}';
        break;
    case '/up':
        header('Content-Type: application/json');
        if ($fixture && isset($_GET['status'])) { http_response_code((int) $_GET['status']); }
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
        header('Location: /up', true, 302);
        break;
    case '/large':
        echo str_repeat('x', 1048577);
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
