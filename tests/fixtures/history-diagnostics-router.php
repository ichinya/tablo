<?php
declare(strict_types=1);
require_once __DIR__ . '/../Support/FixtureRequest.php';

$path = \Tablo\Tests\Support\FixtureRequest::path($_SERVER['REQUEST_URI']);
header('X-Arbitrary-Secret: synthetic-history-header-marker');
header('Content-Type: application/json');
switch (true) {
case ($path === '/health'):
 echo '{"status":"ok","private":"synthetic-history-body-marker"}';
    break;
case ($path === '/broken'):
 echo '{"private":"synthetic-history-body-marker"';
    break;
case ($path === '/missing'):
 echo '{"private":"synthetic-history-body-marker"}';
    break;
case ($path === '/truncated'):
 header('Content-Length: 1000'); echo '{}';
    break;
case ($path === '/unavailable'):
 http_response_code(503); echo 'synthetic-history-body-marker';
    break;
case ($path === '/version'):
 echo '{"version":"v1","commit":"ABCDEF1","private":"synthetic-history-body-marker"}';
    break;
case (str_starts_with($path, '/search/')):
 echo '{"incomplete_results":false,"total_count":3}';
    break;
case (str_ends_with($path, '/releases/latest')):

    switch (true) {
case (str_contains($path, '/access/')):
 http_response_code(403); echo '{"message":"synthetic-history-body-marker"}';
    break;
case (str_contains($path, '/bad-release/')):
 echo '{"tag_name":[]}';
    break;
case (str_contains($path, '/no-release/')):
 http_response_code(404); echo '{}';
    break;
case (str_contains($path, '/rate/')):
 http_response_code(429); header('Retry-After: 60'); echo '{}';
    break;
default:
 echo '{"tag_name":"v2"}';
    break;
}

    break;
case (str_contains($path, '/branches/')):

    echo json_encode(['name' => str_contains($path, '/wrong-branch/') ? 'other' : 'main', 'commit' => ['sha' => str_repeat('a', times: 40)]]);

    break;
case (str_starts_with($path, '/repos/')):
 echo '{}';
    break;
default:
 http_response_code(404);
    break;
}
