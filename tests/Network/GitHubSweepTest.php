<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitTokenRepository;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class GitHubSweepTest
{
    public array $measurements = [];

    #[Test]
    public function measuresTwentySitesAcrossCredentialAndRepositoryModes(): void
    {
        $directory = new TemporaryDirectory('tablo-github-sweep-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            foreach (['shared', 'distinct', 'anonymous'] as $mode) {
                foreach ([1, 20] as $unique) {
                    foreach ([true, false] as $release) {
                        $db = Database::connect(':memory:');
                        $vault = new TokenVault($directory->path . '/key');
                        $tokens = new GitTokenRepository($db, $vault);
                        $sites = new SiteRepository($db, $vault);
                        $shared = $mode === 'shared' ? $tokens->save(['name' => 'Shared', 'provider' => 'github',
                            'token' => bin2hex(random_bytes(24))]) : null;
                        for ($i = 0; $i < 20; ++$i) {
                            $token = $mode === 'distinct' ? $tokens->save(['name' => 'Token ' . $i, 'provider' => 'github',
                                'token' => bin2hex(random_bytes(24))]) : $shared;
                            $sites->save(array_replace(SiteRepository::defaults(), ['name' => 'Fixture ' . $i,
                                'url' => 'https://example.com', 'repository' => 'fixture/' . ($release ? 'release-' : 'no-release-') . ($i % $unique),
                                'git_token_id' => $token, 'version_path' => '']));
                        }
                        $http = new MeasuredGitHubHttp($server->base);
                        $connection = new GitHubConnection($sites, $http);
                        $start = hrtime(true);
                        foreach ($sites->all() as $site) {
                            $state = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $connection->provider($site)))->check($site);
                            Assert::same($state['online'], 1);
                            Assert::same($state['last_error'], null);
                            Assert::same($state['latest_release'], $release ? 'v1' : null);
                            Assert::same($state['open_issues'], 2);
                            Assert::same($state['open_prs'], 2);
                            Assert::true($sites->storeCheck($site, $state));
                        }
                        $multiplier = $unique === 1 && $mode !== 'distinct' ? 1 : 20;
                        Assert::same($http->core, ($release ? 2 : 3) * $multiplier);
                        Assert::same($http->search, 2 * $multiplier);
                        $this->measurements[] = ['credential_mode' => $mode, 'unique_repositories' => $unique,
                            'has_release' => $release, 'sites' => 20, 'requests' => $http->core + $http->search,
                            'core' => $http->core, 'search' => $http->search, 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2)];
                        unset($connection, $http, $sites, $tokens, $vault, $db);
                    }
                }
            }
        } finally {
            unset($connection, $http, $sites, $tokens, $vault, $db);
            $server?->close();
            $directory->close();
        }
    }

    #[Test]
    public function twentyLimitedSitesSendOnlyOneRequestAndKeepHealth(): void
    {
        $directory = new TemporaryDirectory('tablo-github-limited-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $db = Database::connect(':memory:');
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/key'));
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            for ($i = 0; $i < 20; ++$i) {
                $site = ['url' => 'https://example.com', 'health_path' => '', 'repository' => 'fixture/limited', 'branch' => 'main'];
                $state = (new SiteChecker(new FakeHttp([UnitFixtures::response()]), $connection->provider($site)))->check($site);
                Assert::same($state['online'], 1);
                Assert::same($state['latest_release'], null);
                Assert::same($state['latest_commit'], null);
                Assert::same($state['open_issues'], null);
                Assert::same($state['open_prs'], null);
                Assert::true(str_contains($state['last_error'], 'лимит API'));
            }
            Assert::same($http->core, 1);
            Assert::same($http->search, 0);
        } finally {
            unset($connection, $http, $sites, $db);
            $server?->close();
            $directory->close();
        }
    }
}
