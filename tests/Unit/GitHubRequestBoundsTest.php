<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\GitHubClock;
use Tablo\GitHubProvider;
use Tablo\GitHubRequestPolicy;
use Tablo\Tests\Support\FakeHttp;
use Testo\Assert;
use Testo\Test;

final class GitHubRequestBoundsTest extends GitHubBudgetFixtureCase
{


    #[Test]
    public function boundsRequestsDeadlinesAndMemoEntriesWithoutRetries(): void
    {
        foreach ([0, 1] as $budget) {
            $http = new FakeHttp([self::response(200, '{"tag_name":"v1"}')]);
            $policy = new GitHubRequestPolicy(maxRequests: $budget);
            $provider = new GitHubProvider($http, '', $policy);
            if ($budget === 1) { Assert::same($provider->getLatestRelease('example/project'), 'v1'); }
            self::failure(static fn () => $provider->getLatestRelease('example/other'), 'budget');
            Assert::same(count($http->requests), $budget);
        }
        $clock = 0;
        $policy = new GitHubRequestPolicy(durationMs: 1, clock: new GitHubClock(static function () use (&$clock): int { return $clock; }));
        $clock = 1_000_000;
        self::failure(static fn () => $policy->before('core', PHP_INT_MAX), 'budget');
        $loads = 0;
        $memo = new GitHubRequestPolicy(maxEntries: 2);
        $loader = static function () use (&$loads): int { return ++$loads; };
        foreach (['one', 'two', 'three', 'one'] as $key) { $memo->remember($memo->identity(\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')), $key, $loader); }
        Assert::same($loads, 4, 'oldest entries evicted');
        $memo->remember($memo->identity(\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')), 'one', $loader);
        Assert::same($loads, 4, 'current entry reused');
        $memo->invalidate($memo->identity(\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')));
        $memo->remember($memo->identity(\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')), 'one', $loader);
        Assert::same($loads, 5);
        $entries = (new \ReflectionProperty($memo, 'memo'))->getValue($memo);
        $keys = array_keys((new \ReflectionProperty($entries, 'memo'))->getValue($entries));
        Assert::false(str_contains(json_encode($keys), \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')));
    }



    #[Test]
    public function memoizesOnlyConfirmedNoReleaseAndSeparatesTokensAndBranches(): void
    {
        $http = new FakeHttp([self::response(404), self::response(200), self::response(404), self::response(404),
            self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}'),
            self::response(200, '{"name":"develop","commit":{"sha":"' . str_repeat('b', times: 40) . '"}}')]);
        $policy = new GitHubRequestPolicy();
        $first = new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'), $policy);
        Assert::same($first->getLatestRelease('example/project'), null);
        Assert::same((new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'), $policy))->getLatestRelease('example/project'), null);
        Assert::same(count($http->requests), 2);
        self::failure(static fn () => (new GitHubProvider($http, '', $policy))->getLatestRelease('example/project'), 'access');
        Assert::same(count($http->requests), 4, 'anonymous cannot reuse authenticated no-release');
        Assert::same($first->getLatestCommit('example/project', 'main'), str_repeat('a', times: 40));
        Assert::same($first->getLatestCommit('example/project', 'develop'), str_repeat('b', times: 40));
        Assert::same($first->getLatestCommit('example/project', 'main'), str_repeat('a', times: 40));
        Assert::same(count($http->requests), 6);
    }
}
