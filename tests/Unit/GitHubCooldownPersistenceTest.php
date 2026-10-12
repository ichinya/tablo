<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubProvider;
use Tablo\GitHubRequestPolicy;
use Tablo\Tests\Support\FakeHttp;
use Testo\Assert;
use Testo\Test;

final class GitHubCooldownPersistenceTest extends GitHubBudgetFixtureCase
{


    #[Test]
    public function persistsCooldownAcrossFreshConnectionsWithoutShorteningOrRotatingItAway(): void
    {
        $db = Database::connect(':memory:');
        $storage = new GitHubCooldownRepository($db);
        $now = 1_700_000_000;
        $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => $now));
        $http = new FakeHttp([self::response(429, '{"message":"synthetic-secret"}',
            ['retry-after' => '86400', 'x-ratelimit-reset' => (string) ($now + 172_800)])]);
        $failure = self::failure(static fn () => (new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret'), $policy))->getLatestRelease('example/project'), 'rate-limit');
        Assert::same($failure->eligibleAt, $now + 172_800);
        $storage->defer('secondary', $now + 60);
        Assert::same($storage->eligibleAt('core'), $now + 172_800);
        $fresh = new GitHubProvider($http, \Tablo\Tests\Support\FixtureCredentials::value(label: 'rotated-secret'), new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => $now)));
        self::failure(static fn () => $fresh->getOpenIssuesCount('example/project'), 'rate-limit');
        Assert::same(count($http->requests), 1);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT resource FROM github_cooldowns'))->fetchAll(\PDO::FETCH_COLUMN), ['secondary']);
        $now += 172_801;
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
        $error = self::failure(static fn () => $policy->observe('search', self::response(403,
            '{"message":"synthetic-secret secondary rate limit exceeded"}', ['retry-after' => 'invalid'])), 'rate-limit');
        Assert::same($error->eligibleAt, 1060);
        self::failure(static fn () => $policy->before('core', PHP_INT_MAX), 'rate-limit');
        $huge = new GitHubRequestPolicy(clock: new GitHubClock(epoch: static fn (): int => 1000));
        Assert::same(self::failure(static fn () => $huge->observe('core', self::response(429, '{}', ['retry-after' => '999999999999999999'])),
            'rate-limit')->eligibleAt, 1_000_000_000_000_000_999);
    }



    #[Test]
    public function primaryScopesSeparateAnonymousAndCredentialsAndRetainSameHandleRotation(): void
    {
        $storage = new GitHubCooldownRepository(Database::connect(':memory:'));
        $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000));
        $limited = self::response(403, '{}', ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '5000']);
        self::failure(static fn () => $policy->observe('core', $limited, 'anonymous'), 'rate-limit');
        self::failure(static fn () => $policy->before('core', PHP_INT_MAX, 'anonymous'), 'rate-limit');
        Assert::true($policy->before('core', PHP_INT_MAX, 'saved:1') > 0, 'anonymous exhaustion does not block auth');
        self::failure(static fn () => $policy->observe('core', $limited, 'saved:1'), 'rate-limit');
        Assert::true($policy->before('core', PHP_INT_MAX, 'saved:2') > 0, 'different stable handles are independent');
        self::failure(static fn () => (new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000)))
            ->before('core', PHP_INT_MAX, 'saved:1'), 'rate-limit');
        self::failure(static fn () => $policy->observe('search', self::response(429, '{}', ['retry-after' => '3600']), 'saved:2'), 'rate-limit');
        foreach (['anonymous', 'saved:1', 'saved:2', 'site:1', 'authenticated'] as $scope) {
            self::failure(static fn () => $policy->before('search', PHP_INT_MAX, $scope), 'rate-limit');
        }
    }
}
