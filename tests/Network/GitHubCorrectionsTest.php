<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubCorrectionsTest extends GitHubCorrectionFixtureCase
{


    #[Test]
    public function unusableHeadersKeepAccessActionableAndAllowOtherCredentials(): void
    {
        $directory = new TemporaryDirectory('tablo-github-access-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $db = Database::connect($directory->path . '/test.sqlite');
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/key'));
            $http = new MeasuredGitHubHttp($server->base);
            $secret = bin2hex(random_bytes(24));
            foreach (['plain', 'duplicate-remaining', 'conflicting-remaining', 'negative-remaining', 'long-remaining',
                'invalid-remaining', 'duplicate-retry', 'conflicting-retry', 'invalid-retry', 'date-retry'] as $case) {
                $provider = (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $secret]);
                $error = self::failure(static fn () => $provider->getLatestRelease('fixture/access-' . $case), 'access');
                Assert::same($error->httpStatus, 403);
                Assert::true(str_contains($error->getMessage(), 'Contents: read'));
                Assert::false(str_contains($error->getMessage(), $secret));
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM github_cooldowns'))->fetchColumn(), 0);
                foreach (['another-token', ''] as $token) {
                    $fresh = (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $token]);
                    Assert::same($fresh->getLatestRelease('fixture/control'), 'v1');
                }
            }
            Assert::same($http->core, 30, 'each access failure and both allowed controls reach real HTTP');
            Assert::false(str_contains($server->diagnostics(), 'fixture-secret'));
            Assert::false(str_contains($server->diagnostics(), $secret));
        } finally {
            unset($error, $provider, $fresh, $sites, $http, $db);
            $server?->close();
            $directory->close();
        }
    }



    #[Test]
    public function affirmativeRateControlsStillPersistCorrectScopes(): void
    {
        $directory = new TemporaryDirectory('tablo-github-positive-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            foreach (['primary', 'primary-429', 'primary-invalid-retry', 'success-zero', 'secondary-429', 'secondary-retry', 'secondary-marker'] as $case) {
                $db = Database::connect(':memory:');
                $sites = new SiteRepository($db, new TokenVault($directory->path . '/key'));
                $http = new MeasuredGitHubHttp($server->base);
                $secret = bin2hex(random_bytes(24));
                $provider = (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $secret]);
                switch (true) {
case ($case === 'success-zero'):
 Assert::same($provider->getLatestRelease('fixture/' . $case), 'v1');
    break;
default:
 self::failure(static fn () => $provider->getLatestRelease('fixture/' . $case), 'rate-limit');
    break;
}
                self::failure(static fn () => $provider->getLatestRelease('fixture/control'), 'rate-limit');
                Assert::same($http->core, 1);
                $secondary = str_starts_with($case, 'secondary-');
                Assert::same((int) \Tablo\SqlStatement::checked($db->query("SELECT COUNT(*) FROM github_cooldowns WHERE scope='shared' AND resource='secondary'"))->fetchColumn(), $secondary ? 1 : 0);
                foreach ([\Tablo\Tests\Support\FixtureCredentials::value(label: 'other-token'), ''] as $token) {
                    $fresh = (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $token]);
                    switch (true) {
case ($secondary):
 self::failure(static fn () => $fresh->getLatestRelease('fixture/control'), 'rate-limit');
    break;
default:
 Assert::same($fresh->getLatestRelease('fixture/control'), 'v1');
    break;
}
                }
                Assert::same($http->core, $secondary ? 1 : 3);
                unset($provider, $fresh, $sites, $http, $db);
            }
        } finally {
            unset($provider, $fresh, $sites, $http, $db);
            $server?->close();
            $directory->close();
        }
    }
}
