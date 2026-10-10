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

final class GitHubKeyCustodyTest
{
    private static function input(#[\SensitiveParameter] string $token): array
    {
        return array_replace(SiteRepository::defaults(), ['name' => 'Fixture', 'url' => 'https://example.com',
            'repository' => 'fixture/control', 'github_token' => $token]);
    }

    private static function invoke(GitHubConnection $connection, string $entry, #[\SensitiveParameter] array $input): void
    {
        match ($entry) {
            'provider' => $connection->provider(null, $input)->getLatestRelease($input['repository']),
            'branches' => $connection->branches($input),
            'validate' => $connection->validate($input),
        };
    }

    private static function rows(\PDO $db): array
    {
        return [
            $db->query('SELECT encrypted_token FROM git_tokens ORDER BY id')->fetchAll(),
            $db->query('SELECT github_token FROM sites ORDER BY id')->fetchAll(),
            $db->query('SELECT * FROM github_cooldowns ORDER BY scope, resource')->fetchAll(),
        ];
    }

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
                if ($kind === 'saved') { $tokens->save(['name' => 'Saved', 'provider' => 'github', 'token' => $secret]); }
                else { $sites->save(self::input($secret)); }
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
                $encrypted = $kind === 'saved' ? $before[0][0]['encrypted_token'] : $before[1][0]['github_token'];
                Assert::same($vault->decrypt($encrypted), $secret);
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
                Assert::same($result['exit_code'], 0);
                Assert::same($result['stderr'], '');
                Assert::false(str_contains($result['stdout'], $secret));
                $observed = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
                Assert::same($observed['outcome'], 'key-unavailable');
                Assert::same($observed['core'], 0);
                Assert::false($observed['traceHasToken']);
                Assert::false(file_exists($key));
            }
            $db = Database::connect($path);
            Assert::same(self::rows($db), $before);
            // The exact original key works from a moved external path, then from its restored default path.
            foreach ([$directory->path . '/moved-original.key', $key] as $restored) {
                if ($restored === $key) { rename($directory->path . '/moved-original.key', $key); }
                Assert::same(hash_file('sha256', $restored), $keyHash);
                $sites = new SiteRepository($db, new TokenVault($restored));
                $connection = new GitHubConnection($sites, $http);
                try { self::invoke($connection, 'branches', self::input($secret)); Assert::true(false); }
                catch (ValidationException $error) { Assert::true(str_contains($error->getMessage(), 'лимит API')); }
                Assert::same($http->core, 1, 'original identity retains persisted cooldown');
                unset($error, $connection, $sites);
            }
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
    public function completeKeyFailureTracesProtectAllEntryArguments(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-trace-');
        $server = null;
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $cases = ['corrupt', 'empty', 'unreadable', 'missing'];
            if (PHP_OS_FAMILY !== 'Windows') { $cases[] = 'denied'; }
            foreach ($cases as $kind) {
                $db = Database::connect($directory->path . '/' . $kind . '.sqlite');
                $key = $directory->path . '/' . $kind . '.key';
                $vault = new TokenVault($key);
                $tokens = new GitTokenRepository($db, $vault);
                $secret = bin2hex(random_bytes(24));
                $tokens->save(['name' => 'Established', 'provider' => 'github', 'token' => $secret]);
                rename($key, $key . '.backup');
                if ($kind === 'corrupt') { file_put_contents($key, 'invalid'); }
                if ($kind === 'empty') { touch($key); }
                // A directory cannot be read as a 32-byte vault file on either platform.
                if ($kind === 'unreadable') { mkdir($key); }
                if ($kind === 'denied') {
                    copy($key . '.backup', $key);
                    chmod($key, 0000);
                    Assert::false(is_readable($key), 'Linux non-root control uses an actual unreadable key file');
                }
                $before = self::rows($db);
                $http = new MeasuredGitHubHttp($server->base);
                $sites = new SiteRepository($db, $vault);
                $connection = new GitHubConnection($sites, $http);
                foreach (['provider', 'branches', 'validate'] as $entry) {
                    try { self::invoke($connection, $entry, self::input($secret)); Assert::true(false, 'invalid key must fail'); }
                    catch (ValidationException $error) {
                        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                            $trace = json_encode([$cause->getTrace(), (string) $cause], JSON_THROW_ON_ERROR);
                            Assert::false(str_contains($trace, $secret), 'complete propagated and previous traces protect manual token');
                            Assert::false(str_contains($cause->getMessage(), $secret));
                            unset($trace);
                        }
                        Assert::same(array_keys($error->errors), ['github_token']);
                    }
                    unset($error, $cause);
                }
                Assert::same($http->core + $http->search, 0);
                Assert::same(self::rows($db), $before);
                if ($kind === 'missing') { Assert::false(file_exists($key)); }
                if ($kind === 'corrupt') { Assert::same(file_get_contents($key), 'invalid'); }
                if ($kind === 'empty') { Assert::same(filesize($key), 0); }
                if ($kind === 'unreadable') { Assert::true(is_dir($key)); }
                if ($kind === 'denied') {
                    chmod($key, 0600);
                    Assert::same(hash_file('sha256', $key), hash_file('sha256', $key . '.backup'));
                }
                Assert::false(str_contains($server->diagnostics(), $secret));
                unset($connection, $sites, $tokens, $vault, $http, $db);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $original);
            unset($error, $cause, $trace, $connection, $sites, $tokens, $vault, $http, $db);
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
            $observed = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
            Assert::same($observed['outcome'], 'ok');
            Assert::same($observed['scope'], $scope);
            Assert::same($observed['core'], 1);
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
