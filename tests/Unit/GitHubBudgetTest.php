<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubProvider;
use Tablo\GitHubRequestPolicy;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Testo\Assert;
use Testo\Test;

final class GitHubBudgetTest extends GitHubBudgetFixtureCase
{


    #[Test]
    public function separatesResourceLimitsAndKeepsSuccessfulPartialMetrics(): void
    {
        $http = new FakeHttp([
            self::response(200, '{"tag_name":"v1"}'),
            self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}'),
            self::response(403, '{"message":"synthetic-secret"}', ['x-ratelimit-remaining' => '0',
                'x-ratelimit-resource' => 'search', 'x-ratelimit-reset' => (string) (time() + 3600)]),
            self::response(200, '{"tag_name":"v2"}'),
        ]);
        $policy = new GitHubRequestPolicy(new GitHubCooldownRepository(Database::connect(':memory:')));
        $provider = new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'), $policy);
        $state = (new SiteChecker(new FakeHttp([self::response(200)]), $provider))->check(
            ['url' => 'https://example.com', 'health_path' => '', 'repository' => 'example/project', 'branch' => 'main']);
        Assert::same($state['online'], 1);
        Assert::same($state['latest_release'], 'v1');
        Assert::same($state['latest_commit'], str_repeat('a', times: 40));
        Assert::same($state['open_issues'], null);
        Assert::same($state['open_prs'], null);
        Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string($state['last_error']), 'лимит API'));
        Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string($state['last_error']), \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')));
        Assert::same(count($http->requests), 3, 'no subsequent search request');
        Assert::same((new GitHubProvider($http, '', $policy))->getLatestRelease('example/other'), 'v2', 'core remains usable');
    }



    #[Test]
    public function classifiesFailuresAndDoesNotCacheThem(): void
    {
        foreach ([[401, [], 'access'], [403, [], 'access'], [404, [], 'access'], [503, [], 'unavailable'],
            [304, [], 'unavailable'], [429, [], 'rate-limit'], [403, ['retry-after' => '60'], 'rate-limit'],
            [403, ['retry-after' => null], 'access'], [403, ['retry-after' => 'invalid'], 'access'],
            [403, ['x-ratelimit-remaining' => null], 'access']] as [$status, $headers, $reason]) {
            $http = new FakeHttp([self::response($status, '{"message":"synthetic-secret"}', $headers),
                self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('b', times: 40) . '"}}')]);
            $provider = new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'));
            self::failure(static fn () => $provider->getLatestCommit('example/project', 'main'), $reason);
            switch (true) {
case ($reason === 'rate-limit'):

                self::failure(static fn () => $provider->getLatestCommit('example/project', 'main'), $reason);
                Assert::same(count($http->requests), 1);

    break;
default:

                Assert::same($provider->getLatestCommit('example/project', 'main'), str_repeat('b', times: 40));
                Assert::same(count($http->requests), 2);

    break;
}
        }
        foreach ([['broken synthetic-secret', 'invalid-data'], ['[]', 'incomplete-search'],
            ['{"total_count":2,"incomplete_results":true}', 'incomplete-search']] as [$body, $reason]) {
            $provider = new GitHubProvider(new FakeHttp([self::response(200, $body),
                self::response(200, '{"total_count":3,"incomplete_results":false}')]));
            self::failure(static fn () => $provider->getOpenIssuesCount('example/project'), $reason);
            Assert::same($provider->getOpenIssuesCount('example/project'), 3);
        }
        $transport = new GitHubProvider(new FakeHttp([new \RuntimeException(\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'))]));
        self::failure(static fn () => $transport->getLatestRelease('example/project'), 'unavailable');
    }
}
