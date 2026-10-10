<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
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

final class ExternalKeyTest
{
    private static function refuses(#[\SensitiveParameter] callable $action): string
    {
        try { $action(); } catch (SharedKeyFailure $error) { return $error->getMessage(); }
        throw new \RuntimeException('Expected shared key refusal.');
    }

    #[Test]
    public function rawBinaryReadOnlyAndRelocationPreserveBothCredentialsAndIdentity(): void
    {
        $directory = new TemporaryDirectory('tablo-external-binary-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $sites = $saved = $vault = null;
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
            chmod($external, 0444);
            putenv('TABLO_TOKEN_KEY_FILE=' . $external);
            $sites = $saved = $vault = $db = null;
            $vault = TokenVault::configured();
            $db = Database::connect($directory->path . '/db.sqlite', $vault);
            foreach ($ciphertexts as $ciphertext) { Assert::same($vault->decrypt($ciphertext), $secret); }
            Assert::same($vault->credentialScope($secret), $scope);
            Assert::same((new TokenVault('ignored-local-file'))->decrypt($vault->encrypt($secret)), $secret);
            Assert::same((new GitTokenRepository($db))->tokenFor(1, 'github'), $secret);
            Assert::same(file_get_contents($external), $raw);
            Assert::false(is_file($key));
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 4);
            Assert::same([$db->query('SELECT github_token FROM sites')->fetchColumn(), $db->query('SELECT encrypted_token FROM git_tokens')->fetchColumn()], $ciphertexts);
        } finally {
            $db = $sites = $saved = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            if (isset($external) && is_file($external)) { chmod($external, 0600); }
            $directory->close();
        }
    }

    #[Test]
    public function invalidExplicitSelectionRefusesBeforeDatabaseRuntimeOrFallback(): void
    {
        $directory = new TemporaryDirectory('tablo-external-refusal-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        try {
            foreach ([0, 1, 31, 33, 64] as $length) { file_put_contents($directory->path . '/' . $length, str_repeat('x', $length)); }
            $paths = [$directory->path . '/missing', $directory->path, 'php://memory', 'file://' . $directory->path . '/32',
                'https://example.com/key', '//example/key', '\\\\example\\key'];
            foreach ([0, 1, 31, 33, 64] as $length) { $paths[] = $directory->path . '/' . $length; }
            if (DIRECTORY_SEPARATOR === '/') { $paths[] = '/dev/null'; }
            foreach ($paths as $path) {
                putenv('TABLO_TOKEN_KEY_FILE=' . $path);
                $message = self::refuses(fn () => Database::connect($directory->path . '/new/db.sqlite'));
                Assert::false(str_contains($message, $directory->path));
                Assert::false(is_dir($directory->path . '/new'));
                Assert::false(is_file($directory->path . '/github-token.key'));
            }
            // Environment APIs cannot carry NUL; the direct external factory must also refuse it.
            putenv('TABLO_TOKEN_KEY_FILE');
            self::refuses(fn () => new TokenVault("bad\0path", true));
            if (DIRECTORY_SEPARATOR === '/') {
                $key = $directory->path . '/permission'; file_put_contents($key, random_bytes(32)); chmod($key, 0000);
                try { self::refuses(fn () => new TokenVault($key, true)); }
                finally { chmod($key, 0600); }
                $fifo = $directory->path . '/fifo';
                if (function_exists('posix_mkfifo')) { posix_mkfifo($fifo, 0600); self::refuses(fn () => new TokenVault($fifo, true)); }
            }
        } finally {
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function exactEmptyAndZeroRelativePathsUseProjectRootAcrossCwd(): void
    {
        $directory = new TemporaryDirectory('tablo-external-selection-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $cwd = getcwd();
        $root = dirname(__DIR__, 2);
        $relative = 'artifacts/key-' . bin2hex(random_bytes(8));
        if (!is_dir($root . '/artifacts')) { mkdir($root . '/artifacts'); }
        try {
            putenv('TABLO_TOKEN_KEY_FILE='); Assert::same(TokenVault::configured(), null);
            putenv('TABLO_TOKEN_KEY_FILE=0'); self::refuses(fn () => TokenVault::configured());
            $raw = random_bytes(32); file_put_contents($root . '/' . $relative, $raw);
            putenv('TABLO_TOKEN_KEY_FILE=' . $relative);
            $vault = TokenVault::configured(); $cipher = $vault->encrypt('synthetic');
            chdir($directory->path);
            Assert::same(TokenVault::configured()->decrypt($cipher), 'synthetic');
            Assert::same(file_get_contents($root . '/' . $relative), $raw);
            if (DIRECTORY_SEPARATOR === '/') {
                symlink($root . '/' . $relative, $directory->path . '/link');
                putenv('TABLO_TOKEN_KEY_FILE=' . $directory->path . '/link');
                Assert::same(TokenVault::configured()->decrypt($cipher), 'synthetic');
            }
        } finally {
            chdir($cwd);
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            if (is_file($root . '/' . $relative)) { unlink($root . '/' . $relative); }
            $directory->close();
        }
    }

    #[Test]
    public function oneWitnessAllowsBadCredentialButNoWitnessRefusesBeforeMigration(): void
    {
        $directory = new TemporaryDirectory('tablo-external-witness-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $vault = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $path = $directory->path . '/db.sqlite'; $key = $directory->path . '/original';
            file_put_contents($key, random_bytes(32)); $vault = new TokenVault($key);
            $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/pre-json-schema.sql'));
            $db->prepare('INSERT INTO sites(name,url,repository,github_token) VALUES(?,?,?,?)')->execute(['Synthetic','https://example.com','fixture/control',$vault->encrypt('synthetic')]);
            $before = $db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $db = null;
            $wrong = $directory->path . '/wrong'; file_put_contents($wrong, random_bytes(32));
            putenv('TABLO_TOKEN_KEY_FILE=' . $wrong);
            Assert::true(str_contains(self::refuses(fn () => Database::connect($path)), 'unverified'));
            $db = new PDO('sqlite:' . $path); Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
            Assert::same($db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll(), $before);
            Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'delete'); $db = null;
            putenv('TABLO_TOKEN_KEY_FILE=' . $key);
            Database::preflightExternalKey($path);
            $db = new PDO('sqlite:' . $path);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
            Assert::same($db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll(), $before);
            $db = null; $db = Database::connect($path);
            $db->exec("INSERT INTO git_tokens(name,provider,encrypted_token) VALUES('Bad','github','v1:bad')");
            $db = null; $db = Database::connect($path); Assert::same((int) $db->query('SELECT count(*) FROM git_tokens')->fetchColumn(), 1);
            $db->exec("UPDATE sites SET github_token='v1:bad'"); $db = null;
            Assert::true(str_contains(self::refuses(fn () => Database::connect($path)), 'unverified'));
        } finally {
            $db = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function noCipherProvidesNoHistoricalOracleAndFutureSchemaIsNotMutated(): void
    {
        $directory = new TemporaryDirectory('tablo-external-no-witness-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE'); $db = $vault = null;
        try {
            $key = $directory->path . '/key'; file_put_contents($key, random_bytes(32));
            putenv('TABLO_TOKEN_KEY_FILE=' . $key);
            $path = $directory->path . '/db.sqlite'; $db = Database::connect($path);
            $vault = TokenVault::forDatabase($db); $scope = $vault->credentialScope('synthetic');
            $db->prepare("INSERT INTO github_cooldowns(scope,resource,eligible_at) VALUES(?,'core',2147483647)")->execute([$scope]);
            $rows = $db->query('SELECT * FROM github_cooldowns')->fetchAll(); $db = $vault = null;
            file_put_contents($key, random_bytes(32));
            $db = Database::connect($path); Assert::same($db->query('SELECT * FROM github_cooldowns')->fetchAll(), $rows);
            $db->exec('PRAGMA user_version=99'); $db = null;
            $caught = null;
            try { Database::connect($path); } catch (\RuntimeException $error) { $caught = $error->getMessage(); unset($error); }
            Assert::true(str_contains($caught, 'Unsupported SQLite schema'));
            $db = new PDO('sqlite:' . $path); Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(),99);
            Assert::same($db->query('SELECT * FROM github_cooldowns')->fetchAll(PDO::FETCH_ASSOC), $rows);
            Assert::false(is_file($directory->path . '/github-token.key'));
        } finally {$db=$vault=null;putenv($environment===false?'TABLO_TOKEN_KEY_FILE':'TABLO_TOKEN_KEY_FILE='.$environment);$directory->close();}
    }

    #[Test]
    public function lifetimeLatchSurvivesDeletedCredentialsAndNeverRegeneratesOnSave(): void
    {
        $directory = new TemporaryDirectory('tablo-external-live-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $sites = $vault = null;
        try {
            foreach ([false, true] as $external) {
                putenv('TABLO_TOKEN_KEY_FILE');
                $key = $directory->path . '/key'; file_put_contents($key, random_bytes(32));
                if ($external) { putenv('TABLO_TOKEN_KEY_FILE=' . $key); }
                $vault = new TokenVault($key); $vault->encrypt('synthetic');
                $db = Database::connect(':memory:', $vault); $sites = new SiteRepository($db, $vault);
                unlink($key);
                self::refuses(fn () => $vault->encrypt('synthetic')); Assert::false(is_file($key));
                self::refuses(fn () => $sites->save(UnitFixtures::site() + ['github_token' => 'synthetic'])); Assert::false(is_file($key));
                foreach (['bad', random_bytes(32)] as $replacement) {
                    file_put_contents($key, $replacement);
                    self::refuses(fn () => $vault->credentialScope('synthetic'));
                    self::refuses(fn () => $sites->assertWorkerKeyAvailable());
                    Assert::same(file_get_contents($key), $replacement);
                }
                $sites = $vault = $db = null;
            }
        } finally {
            $db = $sites = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function newExternalPathAndSecretArgumentsAreRedactedInActualPropagatedTraces(): void
    {
        $directory = new TemporaryDirectory('tablo-external-trace-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE'); $ignore = ini_get('zend.exception_ignore_args');
        $caught = $db = $vault = $repository = null;
        try {
            ini_set('zend.exception_ignore_args','0'); putenv('TABLO_TOKEN_KEY_FILE');
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
            $caught = $previous = $sites = null;
            $repository = new GitTokenRepository($db, $vault); unlink($path);
            try { $repository->save(['name' => 'Synthetic','provider' => 'github','token' => $marker]); }
            catch (SharedKeyFailure $error) { $caught = $error; unset($error); }
            Assert::same(TraceInspector::inspect($caught, [$marker])['leaked'], false);
        } finally {
            $caught = $db = $vault = $repository = null; gc_collect_cycles();
            ini_set('zend.exception_ignore_args',$ignore);
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }
}
