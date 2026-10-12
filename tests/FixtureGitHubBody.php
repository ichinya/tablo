<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

final class FixtureGitHubBody
{
    public static function select(string $path, #[\SensitiveParameter] string $url, #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] array $headers, FixtureGitHubResponse $response): void
    {
        $branches = ['main', 'develop', 'feature/login'];
        $sha = str_repeat('a', times: 40);
        switch (true) {
case (str_ends_with($path, '/branches')):

            $response->body = array_map(static fn ($name) => ['name' => $name, 'commit' => ['sha' => $sha]], $branches);

    break;
case (str_contains($path, '/branches/')):

            $branch = rawurldecode(substr($path, strpos($path, needle: '/branches/') + 10));
            if (!in_array($branch, $branches, strict: true)) { $response->status = 404; }
            $response->body = ['name' => $branch, 'commit' => ['sha' => $sha]];

    break;
case (str_contains($path, '/commits/')):

            $response->body = ['sha' => $sha];

    break;
case (str_ends_with($path, '/releases/latest')):

            $response->body = ['tag_name' => 'v1.0.0'];

    break;
case ($path === '/search/issues'):

            if (str_contains($authorization, 'Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'rate-fixture-token'))) {
                $response->status = 403;
                $response->headers = ['x-ratelimit-remaining' => '0', 'x-ratelimit-resource' => 'search',
                    'x-ratelimit-reset' => (string) (time() + 3600)];
            }
            $response->body = ['total_count' => 2, 'incomplete_results' => false];
            if (str_contains($url, 'fixture%2Fprivate') && !in_array('Authorization: Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-token'), $headers, strict: true)
                && !in_array('Authorization: Bearer ' . \Tablo\Tests\Support\FixtureCredentials::value(label: 'replacement-token'), $headers, strict: true)) { $response->status = 404; }

    break;
default:

            $response->body = ['default_branch' => 'main'];

    break;
}

    }
}
