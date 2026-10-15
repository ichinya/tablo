<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordSelectionTest extends AdminPasswordFixtureCase
{


    #[Test]
    public function selectsActualExistingFileAndRejectsTransientMissingAndForgedInstallations(): void
    {
        $directory = new TemporaryDirectory('tablo selection ж ');
        $db = null;
        try {
            $path = $directory->path . '/space ж.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $db = null;
            $uri = 'file:' . str_replace('%2F', replace: '/', subject: rawurlencode(str_replace('\\', replace: '/', subject: $path)));
            foreach (InstallationSelectionCases::admitted($path, $uri) as $selection) {
                $result = self::command($directory, $selection, ['--show-installation']);
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(str_contains($result['stdout'], 'administrator=1; schema=' . Database::CURRENT_SCHEMA_VERSION));
                Assert::true(!str_contains($result['stdout'], '?mode='));
                Assert::true(str_contains($result['stdout'], 'space ж.sqlite'));
            }
            foreach (InstallationSelectionCases::refused($directory, $uri) as $selection) {
                Assert::same(self::command($directory, $selection, ['--show-installation'])['exit_code'], 3);
                Assert::true(!file_exists($directory->path . '/missing') && !file_exists($directory->path . '/missing.sqlite'));
            }
            foreach (['?nolock%00suffix=1', '?immutable%00suffix=1', '?mode%00suffix=ro', '%00suffix?mode=rw',
                '?private=ignored%00suffix', '?mode=%72%6f', '?immutable=1', '?nolock=1'] as $suffix) {
                Assert::same(self::command($directory, $uri . $suffix, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'))['exit_code'], 3);
            }
            foreach ([0, 1, Database::CURRENT_SCHEMA_VERSION + 1] as $version) {
                $db = new PDO('sqlite:' . $path);
                $db->exec('PRAGMA user_version=' . $version);
                $db = null;
                $before = hash_file('sha256', $path);
                Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
                Assert::same(hash_file('sha256', $path), $before);
            }
            $db = new PDO('sqlite:' . $path);
            $db->exec('PRAGMA user_version=' . Database::CURRENT_SCHEMA_VERSION . '; ALTER TABLE users RENAME TO original_users;
                CREATE TABLE users(id INTEGER PRIMARY KEY,password_hash TEXT NOT NULL,created_at TEXT NOT NULL);
                INSERT INTO users SELECT * FROM original_users;');
            $db = null;
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
            file_put_contents($directory->path . '/corrupt.sqlite', data: 'corrupt synthetic file');
            Assert::same(self::command($directory, $directory->path . '/corrupt.sqlite', ['--show-installation'])['exit_code'], 3);
            // Removal after discovery cannot turn the subsequent real READWRITE open into CREATE.
            unlink($path);
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
            Assert::true(!is_file($path));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }



    #[Test]
    public function refusesEveryExplicitDecodedVfsBeforeSelectionOrWrites(): void
    {
        $directory = new TemporaryDirectory('tablo-password-vfs-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $db->exec("INSERT INTO login_limits VALUES ('blocked-fixture', 9, 123)");
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $limits = $db->query('SELECT * FROM login_limits')->fetchAll();
            $schema = $db->query('SELECT * FROM sqlite_schema ORDER BY name')->fetchAll();
            $db = null;
            $bytes = hash_file('sha256', $path);
            $uri = 'file:' . str_replace('%2F', replace: '/', subject: rawurlencode(str_replace('\\', replace: '/', subject: $path)));
            foreach (['vfs=unix-none', 'vfs=win32-none', '%76%66%73=%75nix%2dnone', 'vfs=', 'vfs',
                'vfs=unix', 'vfs=unknown', 'vfs=unix-none&vfs=unix', 'vfs=unix&vfs=unix-none',
                'vfs=&%76fs=unix-none', 'vfs=unix-none&nolock=0&immutable=0',
                'nolock=0&immutable=0&%76fs=%77in32%2dnone', 'vfs=unix-none#ignored'] as $query) {
                foreach (['--show-installation', '--password-stdin'] as $mode) {
                    $result = self::command($directory, $uri . '?mode=rw&' . $query, [$mode],
                        $mode === '--password-stdin' ? \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret') : '');
                    Assert::same($result['exit_code'], 3, 'explicit VFS must refuse as an installation error');
                    Assert::same($result['stdout'], '', 'refusal precedes the installation selection line');
                    Assert::true(!str_contains($result['stderr'], $query));
                    Assert::same(hash_file('sha256', $path), $bytes, 'all installation bytes retained');
                    Assert::true(!is_file($path . '-journal') && !is_file($path . '-wal'));
                }
            }
            $db = Database::openExisting($path);
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same($db->query('SELECT * FROM login_limits')->fetchAll(), $limits);
            Assert::same($db->query('SELECT * FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
            Assert::true(!(new Auth($db))->needsSetup());
            Assert::true(password_verify(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), $old[0]['password_hash']));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $schema = null; $limits = null; $old = null; $directory->close(); }
    }



    #[Test]
    public function refusesMissingOrMalformedAdminAndNeverTouchesMissingOrUnusableKey(): void
    {
        $directory = new TemporaryDirectory('tablo-password-installation-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            mkdir($directory->path . '/sessions');
            file_put_contents($directory->path . '/sessions/sess_fixture', data: 'synthetic session bytes');
            Assert::same(self::command($directory, $path, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'))['exit_code'], 0);
            Assert::true(!file_exists($directory->path . '/github-token.key'));
            mkdir($directory->path . '/github-token.key'); // unusable as a key file; CLI must not open it
            Assert::same(self::command($directory, $path, ['--password-stdin'], "another-new-secret\nanother-new-secret\n")['exit_code'], 0);
            Assert::true(is_dir($directory->path . '/github-token.key'));
            Assert::same(file_get_contents($directory->path . '/sessions/sess_fixture'), 'synthetic session bytes');
            $db->exec("UPDATE users SET password_hash='not-a-password-hash'");
            $old = $db->query('SELECT * FROM users')->fetchAll();
            Assert::same(self::command($directory, $path, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'))['exit_code'], 3);
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }



    #[Test]
    public function rejectsHttpSapiBeforeOpeningInstallation(): void
    {
        $directory = new TemporaryDirectory('tablo-cli-sapi-');
        $server = null;
        try {
            $path = $directory->path . '/never-created.sqlite';
            $server = new TestServer($directory, dirname(__DIR__) . '/password-cli-router.php', environment: ['TABLO_DB' => $path]);
            $curl = curl_init($server->base);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5]);
            Assert::same(curl_exec($curl), '');
            Assert::same(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 404);
            Assert::true(!file_exists($path));
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $curl = null; $server?->close(); $directory->close(); }
    }



    #[Test]
    public function actualOpenRefusesAFileRemovedAtResolutionTime(): void
    {
        $directory = new TemporaryDirectory('tablo-file-race-');
        $db = null;
        try {
            $path = $directory->path . '/race.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $db = null;
            $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/password-file-race.php', '--show-installation'],
                $directory, ['TABLO_DB' => $path, 'TABLO_RACE_FILE' => $path]);
            Assert::same($result['exit_code'], 3);
            Assert::true(!file_exists($path), 'actual no-create flags survive missing-file race');
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }
}
