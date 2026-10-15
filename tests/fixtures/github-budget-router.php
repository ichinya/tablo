<?php
declare(strict_types=1);

// Local synthetic REST only; the test transport maps the fixed GitHub origin here.
usleep(5000);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (preg_match('~/fixture/access-(.+?)(?:/|$)~', $path, $match)) {
    http_response_code(403);
    switch ($match[1]) {
        case 'duplicate-remaining':
            header('X-RateLimit-Remaining: 42', false);
            header('X-RateLimit-Remaining: 42', false);
            break;
        case 'conflicting-remaining':
            header('X-RateLimit-Remaining: 0', false);
            header('X-RateLimit-Remaining: 42', false);
            break;
        case 'negative-remaining': header('X-RateLimit-Remaining: -1'); break;
        case 'long-remaining': header('X-RateLimit-Remaining: 9999999999999999999'); break;
        case 'invalid-remaining': header('X-RateLimit-Remaining: unknown'); break;
        case 'duplicate-retry':
            header('Retry-After: 60', false);
            header('Retry-After: 60', false);
            break;
        case 'conflicting-retry':
            header('Retry-After: 60', false);
            header('Retry-After: 600', false);
            break;
        case 'invalid-retry': header('Retry-After: later'); break;
        case 'date-retry': header('Retry-After: Wed, 21 Oct 2037 07:28:00 GMT'); break;
    }
    echo json_encode(['message' => 'Resource not accessible by personal access token ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')]);
} elseif (str_contains($path, '/fixture/primary') || str_contains($path, '/fixture/success-zero')) {
    http_response_code(str_contains($path, 'success-zero') ? 200 : (str_contains($path, 'primary-429') ? 429 : 403));
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Resource: core');
    header('X-RateLimit-Reset: ' . (time() + 3600));
    if (str_contains($path, 'primary-invalid-retry')) { header('Retry-After: invalid'); }
    echo '{"tag_name":"v1"}';
} elseif (str_contains($path, '/fixture/secondary-')) {
    http_response_code(str_contains($path, 'secondary-429') ? 429 : 403);
    if (str_contains($path, 'secondary-retry')) { header('Retry-After: 3600'); }
    else { header('Retry-After: invalid'); }
    echo json_encode(['message' => str_contains($path, 'secondary-marker') ? 'You have exceeded a secondary rate limit' : 'error']);
} elseif (preg_match('~/fixture/redirect-(301|302)~', $path, $match)) {
    http_response_code((int) $match[1]);
    header('Location: https://untrusted.example/fixture-secret');
    echo '{"message":"fixture-secret"}';
} elseif (str_contains($path, '/fixture/wrong-branch')) {
    echo json_encode(['name' => 'fixture-secret', 'commit' => ['sha' => str_repeat('b', 40)]]);
} elseif (str_contains($path, '/fixture/invalid-branch')) {
    echo '{"name":"main","commit":{"sha":"fixture-secret"}}';
} elseif (str_contains($path, '/fixture/large-branches') && str_ends_with($path, '/branches')) {
    echo json_encode(array_map(static fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', 40)]], range(1, 100)));
} elseif (str_contains($path, '/fixture/malformed')) {
    echo '{fixture-secret';
} elseif (str_contains($path, '/fixture/transient')) {
    http_response_code(503);
    echo '{"message":"fixture-secret"}';
} elseif (str_contains($path, '/fixture/limited')) {
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
