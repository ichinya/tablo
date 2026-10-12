<?php
declare(strict_types=1);

$directory = getenv('TABLO_WORKER_FIXTURE');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path,
    'window' => (int) ($_SERVER['HTTP_X_FIXTURE_WINDOW'] ?? 0)]) . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/barrier') {
    file_put_contents($directory . '/entered', data: 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file($directory . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
}
header('Content-Type: application/json');
if (str_contains($path, '/fixture/core-limited/')) {
    http_response_code(403);
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Resource: core');
    header('X-RateLimit-Reset: ' . (time() + 3600));
    echo '{"message":"synthetic-private-response"}';
    return;
}
if (str_contains($path, '/fixture/secondary-limited/')) {
    http_response_code(429);
    header('Retry-After: 60');
    echo '{"message":"synthetic-private-response"}';
    return;
}
switch (true) {
case (str_starts_with($path, '/search/')):

    if (getenv('TABLO_WORKER_ONE_SEARCH') === '1') {
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Resource: search');
        header('X-RateLimit-Reset: ' . (1_000_060 + 60 * (int) ($_SERVER['HTTP_X_FIXTURE_WINDOW'] ?? 0)));
    }
    echo '{"total_count":2,"incomplete_results":false}';

    break;
case (str_ends_with($path, '/releases/latest')):

    switch (true) {
case (str_contains($path, 'no-release')):
 http_response_code(404); echo '{}';
    break;
default:
 echo '{"tag_name":"v1"}';
    break;
}

    break;
case (str_contains($path, '/branches/')):

    echo json_encode(['name' => 'main', 'commit' => ['sha' => str_repeat('a', times: 40)]]);

    break;
case (str_starts_with($path, '/repos/')):
 echo '{}';
    break;
default:
 echo json_encode(['version' => 'v1', 'commit' => str_repeat('a', times: 40)]);
    break;
}
