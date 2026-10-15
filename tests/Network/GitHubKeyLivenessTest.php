<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitHubFailure;
use Tablo\SiteRepository;
use Tablo\ValidationException;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubKeyLivenessTest extends GitHubKeyFixtureCase
{


    #[Test]
    public function cooldownOnlyKeyLossRefusesFreshProcessesAndOriginalKeyRestoresEquivalence(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-cooldown-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $path = $directory->path . '/quota.sqlite';
            $key = $directory->path . '/github-token.key';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            $secret = bin2hex(random_bytes(24));
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            try { $connection->provider(null, self::input($secret))->getLatestRelease('fixture/primary'); Assert::true(false); }
            catch (GitHubFailure $error) { Assert::same($error->reason, 'rate-limit'); }
            $before = self::rows($db);
            Assert::same($before[0], []);
            Assert::same($before[1], []);
            Assert::same(count($before[2]), 1);
            Assert::true(str_starts_with($before[2][0]['scope'], 'credential:v1:'));
            Assert::same($http->core, 1);
            $keyHash = hash_file('sha256', $key);
            rename($key, $directory->path . '/moved-original.key');
            unset($error, $connection, $sites, $db);
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $result = Subprocess::run([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
                    dirname(__DIR__) . '/fixtures/github-key-preview.php'], $directory, [
                    'TABLO_TEST_PREVIEW_DB' => $path, 'TABLO_TEST_PREVIEW_KEY' => $key,
                    'TABLO_TEST_PREVIEW_BASE' => $server->base, 'TABLO_TEST_PREVIEW_TOKEN' => $secret,
                ]);
                KeyPreviewAssertions::refused($result, $secret, $key);
            }
            $db = Database::connect($path);
            Assert::same(self::rows($db), $before);
            // The exact original key works from a moved external path, then from its restored default path.
            (new KeyRestoreAssertions($directory, $db, $http))->equivalent($secret, [$key, $keyHash]);
            $sites = new SiteRepository($db);
            $connection = new GitHubConnection($sites, $http);
            foreach ([bin2hex(random_bytes(24)), ''] as $allowed) {
                Assert::same($connection->provider(null, self::input($allowed))->getLatestRelease('fixture/control'), 'v1');
            }
            Assert::same($http->core, 3);
            Assert::false(str_contains(file_get_contents($path), $secret));
            Assert::false(str_contains($server->diagnostics(), $secret));
        } finally {
            unset($error, $connection, $sites, $http, $db);
            $server?->close();
            $directory->close();
        }
    }



    #[Test]
    public function expiredKeyIdentityStillRequiresTheOriginalKey(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-expired-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $db = Database::connect($directory->path . '/expired.sqlite');
            $sites = new SiteRepository($db);
            $secret = bin2hex(random_bytes(24));
            $scope = $sites->equivalentCredentialScope($secret);
            $sites->githubCooldowns()->defer('core', time() - 1, $scope);
            $before = self::rows($db);
            $key = $directory->path . '/github-token.key';
            rename($key, $key . '.backup');
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            try { self::invoke($connection, 'validate', self::input($secret)); Assert::true(false); }
            catch (ValidationException $error) { Assert::true(str_contains($error->getMessage(), 'Восстановите его из резервной копии')); }
            Assert::false(file_exists($key));
            Assert::same($http->core, 0);
            Assert::same(self::rows($db), $before);
            rename($key . '.backup', $key);
            Assert::same($sites->equivalentCredentialScope($secret), $scope);
            Assert::same($connection->provider(null, self::input($secret))->getLatestRelease('fixture/control'), 'v1');
            Assert::same($http->core, 1);
        } finally {
            unset($error, $connection, $sites, $http, $db);
            $server?->close();
            $directory->close();
        }
    }
}
