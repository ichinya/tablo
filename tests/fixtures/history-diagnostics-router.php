<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('X-Arbitrary-Secret: synthetic-history-header-marker');
header('Content-Type: application/json');
if ($path === '/health') { echo '{"status":"ok","private":"synthetic-history-body-marker"}'; }
elseif ($path === '/broken') { echo '{"private":"synthetic-history-body-marker"'; }
elseif ($path === '/missing') { echo '{"private":"synthetic-history-body-marker"}'; }
elseif ($path === '/truncated') { header('Content-Length: 1000'); echo '{}'; }
elseif ($path === '/unavailable') { http_response_code(503); echo 'synthetic-history-body-marker'; }
elseif ($path === '/version') { echo '{"version":"v1","commit":"ABCDEF1","private":"synthetic-history-body-marker"}'; }
elseif (str_starts_with($path, '/search/')) { echo '{"incomplete_results":false,"total_count":3}'; }
elseif (str_ends_with($path, '/releases/latest')) {
    if (str_contains($path, '/access/')) { http_response_code(403); echo '{"message":"synthetic-history-body-marker"}'; }
    elseif (str_contains($path, '/bad-release/')) { echo '{"tag_name":[]}'; }
    elseif (str_contains($path, '/no-release/')) { http_response_code(404); echo '{}'; }
    elseif (str_contains($path, '/rate/')) { http_response_code(429); header('Retry-After: 60'); echo '{}'; }
    else { echo '{"tag_name":"v2"}'; }
} elseif (str_contains($path, '/branches/')) {
    echo json_encode(['name' => str_contains($path, '/wrong-branch/') ? 'other' : 'main', 'commit' => ['sha' => str_repeat('a', 40)]]);
} elseif (str_starts_with($path, '/repos/')) { echo '{}'; }
else { http_response_code(404); }
