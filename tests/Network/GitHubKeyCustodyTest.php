<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
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

final class GitHubKeyCustodyTest extends GitHubKeyFixtureCase
{


    #[Test]
    public function missingKeyWithEitherCiphertextRefusesEveryManualEntryBeforeHttp(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-ciphertext-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            foreach (['saved', 'site'] as $kind) {
                $path = $directory->path . '/' . $kind . '.sqlite';
                $key = $directory->path . '/' . $kind . '.key';
                $db = Database::connect($path);
                $vault = new TokenVault($key);
                $sites = new SiteRepository($db, $vault);
                $tokens = new GitTokenRepository($db, $vault);
                $secret = bin2hex(random_bytes(24));
                CiphertextKeyFixture::seed($kind, $tokens, $sites, $secret);
                $before = self::rows($db);
                Assert::same($before[2], [], 'ciphertext alone establishes key custody');
                $keyHash = hash_file('sha256', $key);
                rename($key, $key . '.backup');
                unset($tokens, $sites, $vault, $db);
                foreach (['provider', 'branches', 'validate'] as $entry) {
                    $db = Database::connect($path);
                    $sites = new SiteRepository($db, new TokenVault($key));
                    $http = new MeasuredGitHubHttp($server->base);
                    $connection = new GitHubConnection($sites, $http);
                    try { self::invoke($connection, $entry, self::input($secret)); Assert::true(false, 'missing installed key must refuse preview'); }
                    catch (ValidationException $error) {
                        Assert::true(str_contains($error->getMessage(), 'Восстановите его из резервной копии'));
                        Assert::false(str_contains($error->getMessage(), $secret));
                    }
                    Assert::false(file_exists($key), 'no replacement key');
                    Assert::same($http->core + $http->search, 0);
                    Assert::same(self::rows($db), $before, 'ciphertext and eligibility remain unchanged');
                    unset($error, $connection, $sites, $http, $db);
                }
                rename($key . '.backup', $key);
                Assert::same(hash_file('sha256', $key), $keyHash);
                $db = Database::connect($path);
                $vault = new TokenVault($key);
                $encrypted = $kind === 'saved' ? \Tablo\Tests\Support\FixtureValue::at($before[0], 0)['encrypted_token'] : \Tablo\Tests\Support\FixtureValue::at($before[1], 0)['github_token'];
                Assert::same($vault->decrypt(\Tablo\Tests\Support\FixtureValue::string($encrypted)), $secret);
                Assert::false(str_contains($server->diagnostics(), $secret));
                unset($vault, $db);
            }
        } finally {
            unset($error, $connection, $sites, $tokens, $vault, $http, $db);
            $server?->close();
            $directory->close();
        }
    }



    #[Test]
    public function emptyInstallationInitializesOneStableKeyAcrossFreshProcesses(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-first-use-');
        $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $path = $directory->path . '/empty.sqlite';
            $key = $directory->path . '/github-token.key';
            $db = Database::connect($path);
            $sites = new SiteRepository($db);
            // Anonymous state without ciphertext or key-derived identities is still genuine first use.
            $sites->save(self::input(''));
            $sites->githubCooldowns()->defer('core', time() - 1, 'anonymous');
            $secret = bin2hex(random_bytes(24));
            $http = new MeasuredGitHubHttp($server->base);
            $connection = new GitHubConnection($sites, $http);
            Assert::false(file_exists($key));
            Assert::same($connection->provider(null, self::input($secret))->getLatestRelease('fixture/control'), 'v1');
            Assert::same(filesize($key), 32);
            $keyHash = hash_file('sha256', $key);
            $scope = $sites->equivalentCredentialScope($secret);
            unset($connection, $sites, $db);
            $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/github-key-preview.php'], $directory, [
                'TABLO_TEST_PREVIEW_DB' => $path, 'TABLO_TEST_PREVIEW_KEY' => $key,
                'TABLO_TEST_PREVIEW_BASE' => $server->base, 'TABLO_TEST_PREVIEW_TOKEN' => $secret,
            ]);
            Assert::same($result['exit_code'], 0);
            Assert::same($result['stderr'], '');
            $observed = \Tablo\JsonRecord::checked(json_decode($result['stdout'], associative: true, depth: 16, flags: JSON_THROW_ON_ERROR));
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($observed, 'outcome'), 'ok');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($observed, 'scope'), $scope);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($observed, 'core'), 1);
            Assert::same(hash_file('sha256', $key), $keyHash);
            $db = Database::connect($path);
            $vault = new TokenVault($key);
            Assert::same($vault->decrypt($vault->encrypt($secret)), $secret);
            Assert::same(hash_file('sha256', $key), $keyHash);
            Assert::false(str_contains($result['stdout'] . $server->diagnostics(), $secret));
        } finally {
            unset($connection, $sites, $vault, $http, $db);
            $server?->close();
            $directory->close();
        }
    }
}
