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
        $authorized = in_array('Authorization: Bearer fixture-token', $headers, strict: true)
            || in_array('Authorization: Bearer replacement-token', $headers, strict: true);
        $status = match (true) {
            str_contains($authorization, 'Bearer invalid-fixture-token') => 401,
            str_contains($authorization, 'Bearer forbidden-fixture-token') => 403,
            str_contains($path, '/fixture/private') && !$authorized => 404,
            default => 200,
        };
        $branches = ['main', 'develop', 'feature/login'];
        $sha = str_repeat('a', times: 40);
        $branch = '';
        if (str_contains($path, '/branches/')) {
            $branch = rawurldecode(substr($path, strpos($path, needle: '/branches/') + 10));
            if (!in_array($branch, $branches, strict: true)) { $status = 404; }
        }
        $body = match (true) {
            str_ends_with($path, '/branches') => array_map(static fn ($name) => ['name' => $name, 'commit' => ['sha' => $sha]], $branches),
            str_contains($path, '/branches/') => ['name' => $branch, 'commit' => ['sha' => $sha]],
            str_contains($path, '/commits/') => ['sha' => $sha],
            str_ends_with($path, '/releases/latest') => ['tag_name' => 'v1.0.0'],
            $path === '/search/issues' => ['total_count' => 2, 'incomplete_results' => false],
            default => ['default_branch' => 'main'],
        };
        if ($path === '/search/issues' && str_contains($url, 'fixture%2Fprivate') && !$authorized) { $status = 404; }
        // An upstream error can reflect sensitive headers; application must discard it.
        if ($status !== 200) { $body = ['message' => 'Reflected ' . $authorization]; }
        return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'time_ms' => 1];
    }
}
