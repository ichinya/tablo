<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TraceInspector;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class ExternalKeyCustodyTest
{
    private static function refuses(#[\SensitiveParameter] callable $action): string
    {
        try { $action(); } catch (SharedKeyFailure $error) { return $error->getMessage(); }
        throw new \RuntimeException('Expected shared key refusal.');
    }

    #[Test]
    public function lifetimeLatchSurvivesDeletedCredentialsAndNeverRegeneratesOnSave(): void
    {
        $directory = new TemporaryDirectory('tablo-external-live-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $vault = null; $sites = null; $db = null;
        try {
            foreach ([false, true] as $external) {
                putenv('TABLO_TOKEN_KEY_FILE');
                $key = $directory->path . '/key'; file_put_contents($key, random_bytes(32));
                if ($external) { putenv('TABLO_TOKEN_KEY_FILE=' . $key); }
                $vault = new TokenVault($key); $vault->encrypt('synthetic');
                $db = Database::connect(':memory:', $vault); $sites = new SiteRepository($db, $vault);
                unlink($key);
                self::refuses(static fn () => $vault->encrypt('synthetic')); Assert::false(is_file($key));
                self::refuses(static fn () => $sites->save(UnitFixtures::site() + ['github_token' => bin2hex(random_bytes(24))])); Assert::false(is_file($key));
                foreach (['bad', random_bytes(32)] as $replacement) {
                    file_put_contents($key, $replacement);
                    self::refuses(static fn () => $vault->credentialScope('synthetic'));
                    self::refuses($sites->assertWorkerKeyAvailable(...));
                    Assert::same(file_get_contents($key), $replacement);
                }
                $db = null; $vault = null; $sites = null;
            }
        } finally {
            $vault = null; $sites = null; $db = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function newExternalPathAndSecretArgumentsAreRedactedInActualPropagatedTraces(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 5);
            return;
        }
        $directory = new TemporaryDirectory('tablo-external-trace-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE'); $ignore = ini_get('zend.exception_ignore_args');
        $repository = null; $vault = null; $db = null; $caught = null;
        try {
            \Tablo\RuntimeIni::set('zend.exception_ignore_args',value: '0'); putenv('TABLO_TOKEN_KEY_FILE');
            $marker = bin2hex(random_bytes(24)); $path = $directory->path . '/' . $marker;
            putenv('TABLO_TOKEN_KEY_FILE=' . $path);
            try { TokenVault::configured(); } catch (SharedKeyFailure $error) { $caught = $error; unset($error); }
            Assert::same(TraceInspector::inspect($caught, [$marker])['leaked'], false); $caught = null;
            file_put_contents($path, random_bytes(32));
            $vault = TokenVault::configured(); $db = Database::connect(':memory:', $vault);
            $sites = new SiteRepository($db, $vault);
            try { new \Tablo\GitHubConnection($sites, []); }
            catch (\TypeError $error) { $caught = $error; unset($error); }
            Assert::true($caught->getTrace()[0]['args'][0] instanceof \SensitiveParameterValue);
            Assert::same(TraceInspector::inspect($caught, [$marker])['leaked'], false);
            $previous = new \RuntimeException('Safe wrapper', previous: $caught);
            Assert::same(TraceInspector::inspect($previous, [$marker])['leaked'], false);
            $sites = null; $previous = null; $caught = null;
            $repository = new GitTokenRepository($db, $vault); unlink($path);
            try { $repository->save(['name' => 'Synthetic','provider' => 'github','token' => $marker]); }
            catch (SharedKeyFailure $error) { $caught = $error; unset($error); }
            Assert::same(TraceInspector::inspect($caught, [$marker])['leaked'], false);
        } finally {
            $repository = null; $vault = null; $db = null; $caught = null; gc_collect_cycles();
            \Tablo\RuntimeIni::set('zend.exception_ignore_args',$ignore);
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }
    #[Test]
    public function rawBinaryReadOnlyAndRelocationPreserveBothCredentialsAndIdentity(): void
    {
        $directory = new TemporaryDirectory('tablo-external-binary-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $vault = null; $saved = null; $sites = null; $db = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $key = $directory->path . '/github-token.key';
            $raw = "\0\n " . random_bytes(29);
            file_put_contents($key, $raw);
            $db = Database::connect($directory->path . '/db.sqlite');
            $vault = new TokenVault($key);
            $secret = bin2hex(random_bytes(24));
            $sites = new SiteRepository($db, $vault);
            $saved = new GitTokenRepository($db, $vault);
            $sites->save(UnitFixtures::site() + ['github_token' => $secret]);
            $saved->save(['name' => 'Synthetic', 'provider' => 'github', 'token' => $secret]);
            $scope = $vault->credentialScope($secret);
            $ciphertexts = [$db->query('SELECT github_token FROM sites')->fetchColumn(), $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn()];
            $external = $directory->path . '/raw key ' . bin2hex(random_bytes(5));
            rename($key, $external);
            chmod($external, permissions: 0o444);
            putenv('TABLO_TOKEN_KEY_FILE=' . $external);
            $db = null; $vault = null; $saved = null; $sites = null;
            $vault = TokenVault::configured();
            $db = Database::connect($directory->path . '/db.sqlite', $vault);
            foreach ($ciphertexts as $ciphertext) { Assert::same($vault->decrypt($ciphertext), $secret); }
            Assert::same($vault->credentialScope($secret), $scope);
            Assert::same((new TokenVault('ignored-local-file'))->decrypt($vault->encrypt($secret)), $secret);
            Assert::same((new GitTokenRepository($db))->tokenFor(1, 'github'), $secret);
            Assert::same(file_get_contents($external), $raw);
            Assert::false(is_file($key));
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same([$db->query('SELECT github_token FROM sites')->fetchColumn(), $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn()], $ciphertexts);
        } finally {
            $vault = null; $saved = null; $sites = null; $db = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            if ((($external ?? null) !== null) && is_file($external)) { chmod($external, permissions: 0o600); }
            $directory->close();
        }
    }

}
