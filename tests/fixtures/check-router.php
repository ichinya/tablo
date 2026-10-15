<?php
declare(strict_types=1);

$directory = getenv('TABLO_CHECK_DIRECTORY');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/barrier') {
    file_put_contents($directory . '/entered', data: 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file($directory . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10_000); }
}
if ($path === '/drop') {
    $db = new PDO('sqlite:' . $directory . '/db.sqlite');
    $db->exec('DROP TABLE sites');
    $db = null;
}
if ($path === '/key-loss') { unlink($directory . '/github-token.key'); }
if ($path === '/offline') { http_response_code(503); }
if ($path === '/bad-version') { echo 'invalid-private-body'; return; }
if ($path === '/utf8') { echo "{\"version\":\"\xff\"}"; return; }
header('Content-Type: application/json');
$fixtureMarker = getenv('TABLO_CHECK_MARKER');
$marker = $fixtureMarker ? $fixtureMarker : 'synthetic-display';
if (str_contains($path, '/core-limited/')) {
    http_response_code(403);
    header('X-RateLimit-Remaining: 0'); header('X-RateLimit-Resource: core'); header('X-RateLimit-Reset: ' . (time() + 3600));
    echo json_encode(['message' => $marker]); return;
}
switch (true) {
case (str_ends_with($path, '/releases/latest')):

    switch (true) {
case (str_contains($path, '/no-release/')):
 http_response_code(404); echo '{}';
    break;
default:
 echo json_encode(['tag_name' => $marker]);
    break;
}

    break;
case (str_contains($path, '/branches/')):

    switch (true) {
case (str_contains($path, '/commit-fails/')):
 http_response_code(503); echo json_encode(['message' => $marker]);
    break;
default:
 echo json_encode(['name' => 'main', 'commit' => ['sha' => str_repeat('a', times: 40)]]);
    break;
}

    break;
case (str_starts_with($path, '/search/')):
 echo '{"total_count":0,"incomplete_results":false}';
    break;
case (str_starts_with($path, '/repos/')):
 echo '{}';
    break;
case ($path === '/reflect'):
 echo json_encode(['version' => substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', strlen('Bearer '))]);
    break;
default:
 echo json_encode(['version' => $marker, 'commit' => str_repeat('b', times: 40)]);
    break;
}
