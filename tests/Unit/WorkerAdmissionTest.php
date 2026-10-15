<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitHubClock;
use Tablo\GitHubCooldownRepository;
use Tablo\GitHubCredential;
use Tablo\GitHubFailure;
use Tablo\GitHubProvider;
use Tablo\GitHubRequestPolicy;
use Tablo\SiteChecker;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerAdmissionTest
{
    #[Test]
    public function observesMaximumWithoutAdmissionAndCreditsNullMemoFailureAndRemainingZero(): void
    {
        $db = Database::connect(':memory:');
        $storage = new GitHubCooldownRepository($db);
        $epoch = 1000;
        $policy = new GitHubRequestPolicy($storage, clock: new GitHubClock(epoch: static fn (): int => 1000));
        $storage->defer('core', 1100, 'saved:1');
        $storage->defer('core', 1200, 'exact-token');
        $storage->defer('secondary', 1150);
        Assert::same($policy->currentEligibility('core', 'saved:1', 'exact-token'), 1200);
        Assert::same($policy->currentEligibility('search', 'saved:1', 'exact-token'), 1150);
        Assert::same($policy->admissions(), 0);
        Assert::same($storage->eligibleAt('core', 'saved:1', false), 1100, 'observation never persists equivalent max');
        $db->exec('DELETE FROM github_cooldowns');
        $http = new FakeHttp([
            UnitFixtures::response(404), UnitFixtures::response(200),
            UnitFixtures::response(503),
            UnitFixtures::response(200, '{"total_count":0,"incomplete_results":false}') + ['headers' =>
                ['x-ratelimit-resource' => 'search', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1100']],
        ]);
        $provider = new GitHubProvider($http, policy: $policy);
        $site = ['url' => 'https://example.com', 'health_path' => '', 'repository' => 'example/project', 'branch' => 'main'];
        $order = ['latest_release','latest_commit','open_issues','open_prs'];
        $result = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $provider))->check($site, $order);
        Assert::same($result['latest_release'], null);
        Assert::same($result['open_issues'], 0);
        Assert::same($result['worker_service'], ['latest_release' => true,'latest_commit' => true,'open_issues' => true,'open_prs' => false]);
        Assert::same($policy->admissions(), 4, 'no-release confirmation counts two core attempts');
        Assert::same($provider->getLatestRelease('example/project'), null, 'validated null memo succeeds without new admission');
        Assert::same($policy->admissions(), 4);
        $stale = new GitHubProvider(new FakeHttp([]), policy: $policy, credential: new GitHubCredential(current: static fn (): bool => false));
        $result = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $stale))->check($site, $order);
        Assert::same($result['worker_service'], array_fill_keys($order, false));
        Assert::same($policy->admissions(), 4);
        $zero = new GitHubProvider(new FakeHttp([]), policy: new GitHubRequestPolicy(maxRequests: 0));
        $result = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $zero))->check($site, $order);
        Assert::same($result['worker_service'], array_fill_keys($order, false));
        Assert::same($result['online'], 1);
    }
}
