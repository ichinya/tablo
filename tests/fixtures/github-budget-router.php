<?php
declare(strict_types=1);

// Local synthetic REST only; the test transport maps the fixed GitHub origin here.
usleep(5000);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
switch (true) {
case (preg_match('~/fixture/access-(.+?)(?:/|$)~', $path, $match)):

    http_response_code(403);
    switch ($match[1]) {
        case 'duplicate-remaining':
            header('X-RateLimit-Remaining: 42', replace: false);
            header('X-RateLimit-Remaining: 42', replace: false);
            break;
        case 'conflicting-remaining':
            header('X-RateLimit-Remaining: 0', replace: false);
            header('X-RateLimit-Remaining: 42', replace: false);
            break;
        case 'negative-remaining': header('X-RateLimit-Remaining: -1'); break;
        case 'long-remaining': header('X-RateLimit-Remaining: 9999999999999999999'); break;
        case 'invalid-remaining': header('X-RateLimit-Remaining: unknown'); break;
        case 'duplicate-retry':
            header('Retry-After: 60', replace: false);
            header('Retry-After: 60', replace: false);
            break;
        case 'conflicting-retry':
            header('Retry-After: 60', replace: false);
            header('Retry-After: 600', replace: false);
            break;
        case 'invalid-retry': header('Retry-After: later'); break;
        case 'date-retry': header('Retry-After: Wed, 21 Oct 2037 07:28:00 GMT'); break;
    }
    echo json_encode(['message' => 'Resource not accessible by personal access token ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')]);

    break;
case (str_contains($path, '/fixture/primary') || str_contains($path, '/fixture/success-zero')):

    http_response_code(str_contains($path, 'success-zero') ? 200 : (match (true) { (str_contains($path, 'primary-429')) => 429, default => 403 }));
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Resource: core');
    header('X-RateLimit-Reset: ' . (time() + 3600));
    if (str_contains($path, 'primary-invalid-retry')) { header('Retry-After: invalid'); }
    echo '{"tag_name":"v1"}';

    break;
case (str_contains($path, '/fixture/secondary-')):

    http_response_code(str_contains($path, 'secondary-429') ? 429 : 403);
    switch (true) {
case (str_contains($path, 'secondary-retry')):
 header('Retry-After: 3600');
    break;
default:
 header('Retry-After: invalid');
    break;
}
    echo json_encode(['message' => str_contains($path, 'secondary-marker') ? 'You have exceeded a secondary rate limit' : 'error']);

    break;
case (preg_match('~/fixture/redirect-(301|302)~', $path, $match)):

    http_response_code((int) $match[1]);
    header('Location: https://untrusted.example/fixture-secret');
    echo '{"message":"fixture-secret"}';

    break;
case (str_contains($path, '/fixture/wrong-branch')):

    echo json_encode(['name' => 'fixture-secret', 'commit' => ['sha' => str_repeat('b', times: 40)]]);

    break;
case (str_contains($path, '/fixture/invalid-branch')):

    echo '{"name":"main","commit":{"sha":"fixture-secret"}}';

    break;
case (str_contains($path, '/fixture/large-branches') && str_ends_with($path, '/branches')):

    echo json_encode(array_map(static fn ($i) => ['name' => 'branch-' . $i, 'commit' => ['sha' => str_repeat('a', times: 40)]], range(1, end: 100)));

    break;
case (str_contains($path, '/fixture/malformed')):

    echo '{fixture-secret';

    break;
case (str_contains($path, '/fixture/transient')):

    http_response_code(503);
    echo '{"message":"fixture-secret"}';

    break;
case (str_contains($path, '/fixture/limited')):

    http_response_code(429);
    header('Retry-After: 3600');
    echo '{"message":"synthetic error body"}';

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

    echo json_encode(['name' => rawurldecode(substr($path, strpos($path, needle: '/branches/') + 10)),
        'commit' => ['sha' => str_repeat('a', times: 40)]], JSON_THROW_ON_ERROR);

    break;
case ($path === '/search/issues'):

    echo '{"total_count":2,"incomplete_results":false}';

    break;
default:

    echo '{"default_branch":"main"}';

    break;
}
