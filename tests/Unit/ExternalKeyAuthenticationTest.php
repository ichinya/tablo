<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ExternalKeyAuthenticationTest
{
    #[Test]
    public function selectedInjectedVaultAuthenticatesBeforeMigrationRegardlessOfEnvironment(): void
    {
        $directory = new TemporaryDirectory('tablo-injected-vault-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $vault = null;
        try {
            foreach (['absent', 'empty', 'environment'] as $selection) {
                putenv('TABLO_TOKEN_KEY_FILE');
                $path = $directory->path . '/' . $selection . '.sqlite';
                $key = $directory->path . '/' . $selection . '.key';
                $wrong = $directory->path . '/' . $selection . '.wrong';
                $originalBytes = random_bytes(32);
                $wrongBytes = random_bytes(32);
                file_put_contents($key, $originalBytes);
                file_put_contents($wrong, $wrongBytes);
                $vault = new TokenVault($key, true);
                $cipher = $vault->encrypt(bin2hex(random_bytes(24)));
                $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/pre-json-schema.sql'));
                $db->prepare('INSERT INTO sites(name,url,repository,github_token) VALUES(?,?,?,?)')
                    ->execute(['Synthetic', 'https://example.com', 'fixture/control', $cipher]);
                $schema = $db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll();
                $db = $vault = null;
                $before = hash_file('sha256', $path);
                if ($selection === 'empty') { putenv('TABLO_TOKEN_KEY_FILE='); }
                if ($selection === 'environment') { putenv('TABLO_TOKEN_KEY_FILE=' . $wrong); }
                $vault = new TokenVault($wrong, true);
                Assert::same(self::refuses(fn () => Database::connect($path, $vault)), SharedKeyFailure::UNVERIFIED_DIAGNOSTIC);
                gc_collect_cycles();
                Assert::same(hash_file('sha256', $path), $before, $selection);
                $db = new PDO('sqlite:' . $path);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'delete');
                Assert::same($db->query('SELECT sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
                Assert::same($db->query('SELECT github_token FROM sites')->fetchColumn(), $cipher);
                Assert::same(file_get_contents($key), $originalBytes);
                Assert::same(file_get_contents($wrong), $wrongBytes);
                Assert::false(is_file($directory->path . '/github-token.key'));
                $db = $vault = null;
                if ($selection === 'environment') { putenv('TABLO_TOKEN_KEY_FILE=' . $key); }
                $vault = new TokenVault($key, true);
                $db = Database::connect($path, $vault);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'wal');
                Assert::same($db->query('SELECT github_token FROM sites')->fetchColumn(), $cipher);
                Assert::same(file_get_contents($key), $originalBytes);
                $db = $vault = null;
            }
        } finally {
            $db = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function injectedExternalVaultRetainsUriAdmissionWithAbsentAndEmptySelector(): void
    {
        $directory = new TemporaryDirectory('tablo-injected-uri-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        try {
            $key = $directory->path . '/key';
            file_put_contents($key, random_bytes(32));
            foreach (['TABLO_TOKEN_KEY_FILE', 'TABLO_TOKEN_KEY_FILE='] as $selection) {
                putenv($selection);
                $vault = new TokenVault($key, true);
                foreach (['mode=memory', 'mode=ro', 'immutable=1', 'nolock=1', 'vfs=unknown'] as $query) {
                    $caught = null;
                    try { Database::connect('file:' . $directory->path . '/absent.sqlite?' . $query, $vault); }
                    catch (\Tablo\InstallationException $error) { $caught = $error->getMessage(); unset($error); }
                    Assert::same($caught, 'Installation unavailable.', $query);
                    Assert::false(is_file($directory->path . '/absent.sqlite'));
                }
            }
        } finally {
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    private static function refuses(#[\SensitiveParameter] callable $action): string
    {
        try { $action(); } catch (SharedKeyFailure $error) { return $error->getMessage(); }
        throw new \RuntimeException('Expected shared key refusal.');
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

}
