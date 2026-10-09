<?php
declare(strict_types=1);

// Local synthetic REST only; the test transport maps the fixed GitHub origin here.
usleep(5000);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_contains($path, '/fixture/limited')) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo '{"message":"synthetic error body"}';
} elseif (str_ends_with($path, '/releases/latest')) {
    if (str_contains($path, 'no-release')) { http_response_code(404); echo '{}'; }
    else { echo '{"tag_name":"v1"}'; }
} elseif (str_contains($path, '/branches/')) {
    echo json_encode(['name' => rawurldecode(substr($path, strpos($path, '/branches/') + 10)),
        'commit' => ['sha' => str_repeat('a', 40)]], JSON_THROW_ON_ERROR);
} elseif ($path === '/search/issues') {
    echo '{"total_count":2,"incomplete_results":false}';
} else {
    echo '{"default_branch":"main"}';
}
