<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubClock;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubFailure;
use Tablo\GitHubProvider;
use Tablo\GitHubRequestPolicy;
use Tablo\GitTokenRepository;
use Tablo\HttpClient;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class GitHubBudgetTest
{
    private static function response(int $status, string $body = '{}', array $headers = []): array
    {
        return UnitFixtures::response($status, $body) + ['headers' => $headers];
    }

    private static function failure(\Closure $call, string $reason): GitHubFailure
    {
        $failure = null;
        try { $call(); } catch (GitHubFailure $caught) { $failure = $caught; }
        Assert::instanceOf($failure, GitHubFailure::class);
        Assert::same($failure->reason, $reason);
        Assert::false(str_contains($failure->getMessage(), 'synthetic-secret'));
        return $failure;
    }

    #[Test]
    public function separatesResourceLimitsAndKeepsSuccessfulPartialMetrics(): void
    {
        $http = new FakeHttp([
            self::response(200, '{"tag_name":"v1"}'),
            self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}'),
            self::response(403, '{"message":"synthetic-secret"}', ['x-ratelimit-remaining' => '0',
                'x-ratelimit-resource' => 'search', 'x-ratelimit-reset' => (string) (time() + 3600)]),
            self::response(200, '{"tag_name":"v2"}'),
        ]);
        $policy = new GitHubRequestPolicy(new GitHubCooldownRepository(Database::connect(':memory:')));
        $provider = new GitHubProvider($http, 'synthetic-secret', $policy);
        $state = (new SiteChecker(new FakeHttp([self::response(200)]), $provider))->check(
            ['url' => 'https://example.com', 'health_path' => '', 'repository' => 'example/project', 'branch' => 'main']);
        Assert::same($state['online'], 1);
        Assert::same($state['latest_release'], 'v1');
        Assert::same($state['latest_commit'], str_repeat('a', 40));
        Assert::same($state['open_issues'], null);
        Assert::same($state['open_prs'], null);
        Assert::true(str_contains($state['last_error'], 'лимит API'));
        Assert::false(str_contains($state['last_error'], 'synthetic-secret'));
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
                self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('b', 40) . '"}}')]);
            $provider = new GitHubProvider($http, 'synthetic-secret');
            self::failure(fn () => $provider->getLatestCommit('example/project', 'main'), $reason);
            if ($reason === 'rate-limit') {
                self::failure(fn () => $provider->getLatestCommit('example/project', 'main'), $reason);
                Assert::same(count($http->requests), 1);
            } else {
                Assert::same($provider->getLatestCommit('example/project', 'main'), str_repeat('b', 40));
                Assert::same(count($http->requests), 2);
            }
        }
        foreach ([['broken synthetic-secret', 'invalid-data'], ['[]', 'incomplete-search'],
            ['{"total_count":2,"incomplete_results":true}', 'incomplete-search']] as [$body, $reason]) {
            $provider = new GitHubProvider(new FakeHttp([self::response(200, $body),
                self::response(200, '{"total_count":3,"incomplete_results":false}')]));
            self::failure(fn () => $provider->getOpenIssuesCount('example/project'), $reason);
            Assert::same($provider->getOpenIssuesCount('example/project'), 3);
        }
        $transport = new GitHubProvider(new FakeHttp([new \RuntimeException('synthetic-secret')]));
        self::failure(fn () => $transport->getLatestRelease('example/project'), 'unavailable');
    }

    #[Test]
    public function persistsCooldownAcrossFreshConnectionsWithoutShorteningOrRotatingItAway(): void
    {
        $db = Database::connect(':memory:');
        $storage = new GitHubCooldownRepository($db);
        $now = 1700000000;
        $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => $now));
        $http = new FakeHttp([self::response(429, '{"message":"synthetic-secret"}',
            ['retry-after' => '86400', 'x-ratelimit-reset' => (string) ($now + 172800)])]);
        $failure = self::failure(fn () => (new GitHubProvider($http, 'synthetic-secret', $policy))->getLatestRelease('example/project'), 'rate-limit');
        Assert::same($failure->eligibleAt, $now + 172800);
        $storage->defer('secondary', $now + 60);
        Assert::same($storage->eligibleAt('core'), $now + 172800);
        $fresh = new GitHubProvider($http, 'rotated-secret', new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => $now)));
        self::failure(fn () => $fresh->getOpenIssuesCount('example/project'), 'rate-limit');
        Assert::same(count($http->requests), 1);
        Assert::same($db->query('SELECT resource FROM github_cooldowns')->fetchAll(\PDO::FETCH_COLUMN), ['secondary']);
        $now += 172801;
        $expired = new GitHubProvider(new FakeHttp([self::response(200, '{"total_count":0,"incomplete_results":false}')]), '',
            new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => $now)));
        Assert::same($expired->getOpenIssuesCount('example/project'), 0);
    }

    #[Test]
    public function observesSuccessfulExhaustionAndSecondaryMarkerWithSafeFallback(): void
    {
        foreach (['core', 'search'] as $resource) {
            $storage = new GitHubCooldownRepository(Database::connect(':memory:'));
            $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000));
            $policy->observe($resource, self::response(200, '{}', ['x-ratelimit-remaining' => '0',
                'x-ratelimit-resource' => $resource, 'x-ratelimit-reset' => '5000']));
            Assert::same($storage->eligibleAt($resource), 5000);
            Assert::same($storage->eligibleAt($resource === 'core' ? 'search' : 'core'), 0);
        }
        $policy = new GitHubRequestPolicy(clock: new GitHubClock(epoch: static fn (): int => 1000));
        $error = self::failure(fn () => $policy->observe('search', self::response(403,
            '{"message":"synthetic-secret secondary rate limit exceeded"}', ['retry-after' => 'invalid'])), 'rate-limit');
        Assert::same($error->eligibleAt, 1060);
        self::failure(fn () => $policy->before('core', PHP_INT_MAX), 'rate-limit');
        $huge = new GitHubRequestPolicy(clock: new GitHubClock(epoch: static fn (): int => 1000));
        Assert::same(self::failure(fn () => $huge->observe('core', self::response(429, '{}', ['retry-after' => '999999999999999999'])),
            'rate-limit')->eligibleAt, 1000000000000000999);
    }

    #[Test]
    public function primaryScopesSeparateAnonymousAndCredentialsAndRetainSameHandleRotation(): void
    {
        $storage = new GitHubCooldownRepository(Database::connect(':memory:'));
        $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000));
        $limited = self::response(403, '{}', ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '5000']);
        self::failure(fn () => $policy->observe('core', $limited, 'anonymous'), 'rate-limit');
        self::failure(fn () => $policy->before('core', PHP_INT_MAX, 'anonymous'), 'rate-limit');
        Assert::true($policy->before('core', PHP_INT_MAX, 'saved:1') > 0, 'anonymous exhaustion does not block auth');
        self::failure(fn () => $policy->observe('core', $limited, 'saved:1'), 'rate-limit');
        Assert::true($policy->before('core', PHP_INT_MAX, 'saved:2') > 0, 'different stable handles are independent');
        self::failure(fn () => (new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000)))
            ->before('core', PHP_INT_MAX, 'saved:1'), 'rate-limit');
        self::failure(fn () => $policy->observe('search', self::response(429, '{}', ['retry-after' => '3600']), 'saved:2'), 'rate-limit');
        foreach (['anonymous', 'saved:1', 'saved:2', 'site:1', 'authenticated'] as $scope) {
            self::failure(fn () => $policy->before('search', PHP_INT_MAX, $scope), 'rate-limit');
        }
    }

    #[Test]
    public function boundsRequestsDeadlinesAndMemoEntriesWithoutRetries(): void
    {
        foreach ([0, 1] as $budget) {
            $http = new FakeHttp([self::response(200, '{"tag_name":"v1"}')]);
            $policy = new GitHubRequestPolicy(maxRequests: $budget);
            $provider = new GitHubProvider($http, '', $policy);
            if ($budget === 1) { Assert::same($provider->getLatestRelease('example/project'), 'v1'); }
            self::failure(fn () => $provider->getLatestRelease('example/other'), 'budget');
            Assert::same(count($http->requests), $budget);
        }
        $clock = 0;
        $policy = new GitHubRequestPolicy(durationMs: 1, clock: new GitHubClock(static function () use (&$clock): int { return $clock; }));
        $clock = 1000000;
        self::failure(fn () => $policy->before('core', PHP_INT_MAX), 'budget');
        $loads = 0;
        $memo = new GitHubRequestPolicy(maxEntries: 2);
        $loader = static function () use (&$loads): int { return ++$loads; };
        foreach (['one', 'two', 'three', 'one'] as $key) { $memo->remember($memo->identity('synthetic-secret'), $key, $loader); }
        Assert::same($loads, 4, 'oldest entries evicted');
        $memo->remember($memo->identity('synthetic-secret'), 'one', $loader);
        Assert::same($loads, 4, 'current entry reused');
        $memo->invalidate($memo->identity('synthetic-secret'));
        $memo->remember($memo->identity('synthetic-secret'), 'one', $loader);
        Assert::same($loads, 5);
        $keys = array_keys((new \ReflectionProperty($memo, 'memo'))->getValue($memo));
        Assert::false(str_contains(json_encode($keys), 'synthetic-secret'));
    }

    #[Test]
    public function memoizesOnlyConfirmedNoReleaseAndSeparatesTokensAndBranches(): void
    {
        $http = new FakeHttp([self::response(404), self::response(200), self::response(404), self::response(404),
            self::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', 40) . '"}}'),
            self::response(200, '{"name":"develop","commit":{"sha":"' . str_repeat('b', 40) . '"}}')]);
        $policy = new GitHubRequestPolicy();
        $first = new GitHubProvider($http, 'synthetic-secret', $policy);
        Assert::same($first->getLatestRelease('example/project'), null);
        Assert::same((new GitHubProvider($http, 'synthetic-secret', $policy))->getLatestRelease('example/project'), null);
        Assert::same(count($http->requests), 2);
        self::failure(fn () => (new GitHubProvider($http, '', $policy))->getLatestRelease('example/project'), 'access');
        Assert::same(count($http->requests), 4, 'anonymous cannot reuse authenticated no-release');
        Assert::same($first->getLatestCommit('example/project', 'main'), str_repeat('a', 40));
        Assert::same($first->getLatestCommit('example/project', 'develop'), str_repeat('b', 40));
        Assert::same($first->getLatestCommit('example/project', 'main'), str_repeat('a', 40));
        Assert::same(count($http->requests), 6);
    }

    #[Test]
    public function sameIdRotationInvalidatesMemoAndRejectsOldProvidersAndInflightResults(): void
    {
        $directory = new TemporaryDirectory('tablo-github-rotation-');
        try {
            $db = Database::connect(':memory:');
            $vault = new TokenVault($directory->path . '/key');
            $tokens = new GitTokenRepository($db, $vault);
            $sites = new SiteRepository($db, $vault);
            $id = $tokens->save(['name' => 'Shared', 'provider' => 'github', 'token' => 'synthetic-secret']);
            $siteId = $sites->save(array_replace(UnitFixtures::site(), ['version_path' => '', 'git_token_id' => $id]));
            $http = new FakeHttp([self::response(200, '{"tag_name":"v1"}'), self::response(200, '{"tag_name":"v2"}'),
                self::response(200, '{"tag_name":"v3"}')]);
            $connection = new GitHubConnection($sites, $http);
            $old = $connection->provider($sites->find($siteId));
            Assert::same($old->getLatestRelease('example/project'), 'v1');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => ''], $id);
            Assert::same($connection->provider($sites->find($siteId))->getLatestRelease('example/project'), 'v1');
            $tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => 'synthetic-secret'], $id);
            self::failure(fn () => $old->getLatestRelease('example/project'), 'credential-changed');
            Assert::same($connection->provider($sites->find($siteId))->getLatestRelease('example/project'), 'v2');
            $snapshot = $sites->find($siteId);
            $rotating = new class($tokens, $id) extends HttpClient {
                public function __construct(private GitTokenRepository $tokens, private int $id) {}
                public function get(string $url, array $headers = []): array
                {
                    $this->tokens->save(['name' => 'Renamed', 'provider' => 'github', 'token' => 'rotated-secret'], $this->id);
                    return UnitFixtures::response(200, '{"tag_name":"obsolete"}');
                }
            };
            $provider = (new GitHubConnection($sites, $rotating))->provider($snapshot);
            self::failure(fn () => $provider->getLatestRelease('example/project'), 'credential-changed');
            Assert::false($sites->storeCheck($snapshot, array_fill_keys(['online','health_error_code','health_http_status',
                'deployed_version','deployed_commit','latest_release','latest_commit','open_issues','open_prs','response_time_ms','last_error','checked_at'], null)));
            Assert::same($sites->find($siteId)['checked_at'], null);
        } finally {
            unset($provider, $rotating, $old, $connection, $sites, $tokens, $vault, $db);
            $directory->close();
        }
    }
}
