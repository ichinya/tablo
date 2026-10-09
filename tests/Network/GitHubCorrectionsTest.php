<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubCorrectionsTest
{
    private static function failure(\Closure $call, string $reason): GitHubFailure
    {
        $error = null;
        try { $call(); } catch (GitHubFailure $caught) { $error = $caught; }
        Assert::instanceOf($error, GitHubFailure::class);
        Assert::same($error->reason, $reason);
        Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
        Assert::false(str_contains($error->getMessage(), 'untrusted.example'));
        return $error;
    }

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
                $error = self::failure(fn () => $provider->getLatestRelease('fixture/access-' . $case), 'access');
                Assert::same($error->httpStatus, 403);
                Assert::true(str_contains($error->getMessage(), 'Contents: read'));
                Assert::false(str_contains($error->getMessage(), $secret));
                Assert::same((int) $db->query('SELECT COUNT(*) FROM github_cooldowns')->fetchColumn(), 0);
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
    public function equivalentTokensSharePrimaryAcrossHandlesConnectionsAndCli(): void
    {
        $directory = new TemporaryDirectory('tablo-github-equivalent-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $vault = new TokenVault($directory->path . '/external.key');
            $sites = new SiteRepository($db, $vault);
            $tokens = new GitTokenRepository($db, $vault);
            $secret = bin2hex(random_bytes(24));
            $other = bin2hex(random_bytes(24));
            $rotated = bin2hex(random_bytes(24));
            $saved = $tokens->save(['name' => 'One', 'provider' => 'github', 'token' => $secret]);
            $duplicate = $tokens->save(['name' => 'Duplicate', 'provider' => 'github', 'token' => $secret]);
            $base = array_replace(SiteRepository::defaults(), ['name' => 'Manual', 'url' => 'https://example.com', 'repository' => 'fixture/primary']);
            $ids = [$sites->save($base + ['github_token' => $secret]), $sites->save($base + ['github_token' => $secret]),
                $sites->save($base + ['git_token_id' => $saved]), $sites->save($base + ['git_token_id' => $duplicate])];
            $sites->save(array_replace($base, ['name' => 'Other', 'repository' => 'fixture/control', 'github_token' => $other]));
            $sites->save(array_replace($base, ['name' => 'Anonymous', 'repository' => 'fixture/control']));
            $http = new MeasuredGitHubHttp($server->base);
            foreach ($ids as $id) {
                // Fresh PDO and connection deliberately share only persisted eligibility and vault key.
                $freshDb = Database::connect($path);
                $freshSites = new SiteRepository($freshDb, new TokenVault($directory->path . '/external.key'));
                self::failure(fn () => (new GitHubConnection($freshSites, $http))->provider($freshSites->find($id))
                    ->getLatestRelease('fixture/primary'), 'rate-limit');
                unset($freshSites, $freshDb);
            }
            Assert::same($http->core, 1, 'only the first proven-equivalent credential reaches upstream');
            self::failure(fn () => (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $secret])
                ->getLatestRelease('fixture/primary'), 'rate-limit');
            Assert::same($http->core, 1, 'an unsaved preview also uses the proven-equivalent quota');
            $rows = $db->query('SELECT scope,resource,eligible_at FROM github_cooldowns')->fetchAll();
            Assert::same(count($rows), 5, 'four observed handles plus one private identity');
            Assert::false(str_contains(json_encode($rows), $secret));
            Assert::false(str_contains(json_encode($rows), hash('sha256', $secret)));
            Assert::same((int) $db->query("SELECT COUNT(*) FROM github_cooldowns WHERE scope='shared'")->fetchColumn(), 0);
            for ($run = 0; $run < 2; ++$run) {
                $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/github-budget-cli.php'], $directory,
                    ['TABLO_TEST_BUDGET_DB' => $path, 'TABLO_TEST_BUDGET_KEY' => $directory->path . '/external.key',
                        'TABLO_TEST_BUDGET_BASE' => $server->base]);
                Assert::same($result['exit_code'], 0);
                Assert::same($result['stderr'], '');
                Assert::same(json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR),
                    ['outcomes' => ['rate-limit', 'rate-limit', 'rate-limit', 'rate-limit', 'ok', 'ok'], 'core' => 2, 'search' => 0]);
                Assert::false(str_contains($result['stdout'] . $result['stderr'], $secret));
            }
            // An equivalent handle deferred without a request must retain eligibility on rotation too.
            $tokens->save(['name' => 'Rotated', 'provider' => 'github', 'token' => $rotated], $duplicate);
            self::failure(fn () => (new GitHubConnection($sites, $http))->provider($sites->find($ids[3]))
                ->getLatestRelease('fixture/control'), 'rate-limit');
            Assert::same($http->core, 1);
            Assert::false(str_contains(file_get_contents($path), $secret));
            Assert::false(str_contains($server->diagnostics(), $secret));
        } finally {
            unset($freshSites, $freshDb, $sites, $tokens, $vault, $http, $db);
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
                if ($case === 'success-zero') { Assert::same($provider->getLatestRelease('fixture/' . $case), 'v1'); }
                else { self::failure(fn () => $provider->getLatestRelease('fixture/' . $case), 'rate-limit'); }
                self::failure(fn () => $provider->getLatestRelease('fixture/control'), 'rate-limit');
                Assert::same($http->core, 1);
                $secondary = str_starts_with($case, 'secondary-');
                Assert::same((int) $db->query("SELECT COUNT(*) FROM github_cooldowns WHERE scope='shared' AND resource='secondary'")->fetchColumn(), $secondary ? 1 : 0);
                foreach (['other-token', ''] as $token) {
                    $fresh = (new GitHubConnection($sites, $http))->provider(null, ['github_token' => $token]);
                    if ($secondary) { self::failure(fn () => $fresh->getLatestRelease('fixture/control'), 'rate-limit'); }
                    else { Assert::same($fresh->getLatestRelease('fixture/control'), 'v1'); }
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

    #[Test]
    public function realRedirectsBranchMismatchAndListSizeKeepSafeGuidance(): void
    {
        $directory = new TemporaryDirectory('tablo-github-guidance-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $db = Database::connect(':memory:');
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/key'));
            foreach ([301, 302] as $status) {
                $http = new MeasuredGitHubHttp($server->base);
                $connection = new GitHubConnection($sites, $http);
                $error = self::failure(fn () => $connection->provider(null)->getLatestRelease('fixture/redirect-' . $status), 'renamed');
                Assert::same($error->httpStatus, $status);
                Assert::true(str_contains($error->getMessage(), 'Укажите актуальный адрес и ветку'));
                Assert::same($http->core, 1, 'redirect is diagnosed without following Location');
            }
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            $secret = bin2hex(random_bytes(24));
            $provider = $connection->provider(null, ['github_token' => $secret]);
            $error = self::failure(fn () => $provider->getLatestCommit('fixture/wrong-branch', 'main'), 'branch-unconfirmed');
            Assert::true(str_contains($error->getMessage(), 'Обновите список веток'));
            self::failure(fn () => $provider->getLatestCommit('fixture/invalid-branch', 'main'), 'invalid-data');
            self::failure(fn () => $provider->getLatestRelease('fixture/malformed'), 'invalid-data');
            self::failure(fn () => $provider->getLatestRelease('fixture/transient'), 'unavailable');
            $largeHttp = new MeasuredGitHubHttp($server->base);
            $large = new GitHubConnection($sites, $largeHttp);
            try { $large->branches(['repository' => 'fixture/large-branches', 'github_token' => $secret]); Assert::true(false); }
            catch (ValidationException $error) {
                Assert::true(str_contains($error->getMessage(), 'Укажите нужную ветку вручную'));
                Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
            }
            Assert::same($largeHttp->core, 11);
            try { $connection->validate(array_replace(SiteRepository::defaults(), ['name' => 'Test',
                'url' => 'https://example.com', 'repository' => 'fixture/wrong-branch', 'github_token' => $secret])); Assert::true(false); }
            catch (ValidationException $error) {
                Assert::true(str_contains($error->getMessage(), 'Обновите список веток'));
                Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
            }
        } finally {
            unset($error, $provider, $large, $connection, $sites, $http, $largeHttp, $db);
            $server?->close();
            $directory->close();
        }
    }
}
