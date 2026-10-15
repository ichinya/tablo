<?php
declare(strict_types=1);

$directory = getenv('TABLO_CHECK_DIRECTORY');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($directory . '/requests.jsonl', json_encode(['path' => $path], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/barrier') {
    file_put_contents($directory . '/entered', 'ready');
    $deadline = microtime(true) + 8;
    while (!is_file($directory . '/release') && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
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
$marker = getenv('TABLO_CHECK_MARKER') ?: 'synthetic-display';
if (str_contains($path, '/core-limited/')) {
    http_response_code(403);
    header('X-RateLimit-Remaining: 0'); header('X-RateLimit-Resource: core'); header('X-RateLimit-Reset: ' . (time() + 3600));
    echo json_encode(['message' => $marker]); return;
}
if (str_ends_with($path, '/releases/latest')) {
    if (str_contains($path, '/no-release/')) { http_response_code(404); echo '{}'; }
    else { echo json_encode(['tag_name' => $marker]); }
} elseif (str_contains($path, '/branches/')) {
    if (str_contains($path, '/commit-fails/')) { http_response_code(503); echo json_encode(['message' => $marker]); }
    else { echo json_encode(['name' => 'main', 'commit' => ['sha' => str_repeat('a', 40)]]); }
} elseif (str_starts_with($path, '/search/')) { echo '{"total_count":0,"incomplete_results":false}'; }
elseif (str_starts_with($path, '/repos/')) { echo '{}'; }
elseif ($path === '/reflect') { echo json_encode(['version' => substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', strlen('Bearer '))]); }
else { echo json_encode(['version' => $marker, 'commit' => str_repeat('b', 40)]); }
