<?php
declare(strict_types=1);

// Synthetic API transport for isolated HTTP/browser tests. Never used by public/index.php.
final class FixtureGitHubHttp extends Tablo\HttpClient
{
    public function get(string $url, array $headers = []): array
    {
        if (!str_starts_with($url, 'https://api.github.com/')) {
            throw new RuntimeException('Unexpected GitHub origin');
        }
        $path = parse_url($url, PHP_URL_PATH);
        $authorization = implode("\n", $headers);
        $status = 200;
        $responseHeaders = [];
        $log = getenv('TABLO_TEST_GITHUB_LOG');
        if ($log) { file_put_contents($log, $path . "\n", FILE_APPEND); }
        if (str_contains($authorization, 'Bearer invalid-fixture-token')) {
            $status = 401;
        } elseif (str_contains($authorization, 'Bearer forbidden-fixture-token')) {
            $status = 403;
        } elseif (str_contains($path, '/fixture/private')
            && !in_array('Authorization: Bearer fixture-token', $headers, true)
            && !in_array('Authorization: Bearer replacement-token', $headers, true)) {
            $status = 404;
        }
        $branches = ['main', 'develop', 'feature/login'];
        $sha = str_repeat('a', 40);
        if (str_ends_with($path, '/branches')) {
            $body = array_map(fn ($name) => ['name' => $name, 'commit' => ['sha' => $sha]], $branches);
        } elseif (str_contains($path, '/branches/')) {
            $branch = rawurldecode(substr($path, strpos($path, '/branches/') + 10));
            if (!in_array($branch, $branches, true)) { $status = 404; }
            $body = ['name' => $branch, 'commit' => ['sha' => $sha]];
        } elseif (str_contains($path, '/commits/')) {
            $body = ['sha' => $sha];
        } elseif (str_ends_with($path, '/releases/latest')) {
            $body = ['tag_name' => 'v1.0.0'];
        } elseif ($path === '/search/issues') {
            if (str_contains($authorization, 'Bearer rate-fixture-token')) {
                $status = 403;
                $responseHeaders = ['x-ratelimit-remaining' => '0', 'x-ratelimit-resource' => 'search',
                    'x-ratelimit-reset' => (string) (time() + 3600)];
            }
            $body = ['total_count' => 2, 'incomplete_results' => false];
            if (str_contains($url, 'fixture%2Fprivate') && !in_array('Authorization: Bearer fixture-token', $headers, true)
                && !in_array('Authorization: Bearer replacement-token', $headers, true)) { $status = 404; }
        } else {
            $body = ['default_branch' => 'main'];
        }
        // An upstream error can reflect sensitive headers; application must discard it.
        if ($status !== 200) { $body = ['message' => 'Reflected ' . $authorization]; }
        return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'time_ms' => 1, 'headers' => $responseHeaders];
    }
}
