<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordTest
{
    private static function command(TemporaryDirectory $directory, string $path, array $arguments,
        #[\SensitiveParameter] string $input = ''): array
    {
        return Subprocess::run([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__, 2) . '/bin/admin-password.php', ...$arguments],
            $directory, ['TABLO_DB' => $path], input: $input, cwd: $directory->path);
    }

    #[Test]
    public function rejectsHostileInvocationAndFramingWithoutWritesOrEcho(): void
    {
        $directory = new TemporaryDirectory('tablo-password-input-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $old = $db->query('SELECT * FROM users')->fetchAll();
            foreach ([[], ['--password=synthetic-private-argv'], ['synthetic-private-argv'], ['--password-stdin', '--password-stdin'],
                ['--show-installation', '--password-stdin'], ['--help', 'synthetic-private-argv']] as $arguments) {
                $result = self::command($directory, $path, $arguments);
                Assert::same($result['exit_code'], 2);
                Assert::true(!str_contains($result['stdout'] . $result['stderr'], 'synthetic-private-argv'));
            }
            foreach (['', "fixture-password\n", "fixture-password\nfixture-password", "fixture-password\nfixture-password\nextra\n",
                "\xEF\xBB\xBFfixture-password\nfixture-password\n", "fixture\0password\nfixture\0password\n", "fixture-password\rfixture-password\r",
                "fixture-password\nother-new-password\n", str_repeat('x', 11) . "\n" . str_repeat('x', 11) . "\n",
                str_repeat('x', 73) . "\n" . str_repeat('x', 73) . "\n", str_repeat('ж', 37) . "\n" . str_repeat('ж', 37) . "\n",
                "invalid-utf8-\xFF\ninvalid-utf8-\xFF\n", str_repeat('oversize', 64)] as $input) {
                $result = self::command($directory, $path, ['--password-stdin'], $input);
                Assert::same($result['exit_code'], 2, $result['stderr']);
                Assert::true(!str_contains($result['stdout'] . $result['stderr'], 'fixture-password'));
                Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            }
            foreach ([str_repeat('x', 12), str_repeat('ж', 36), '  retained spaces  '] as $password) {
                $result = self::command($directory, $path, ['--password-stdin'], $password . "\r\n" . $password . "\r\n");
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(password_verify($password, $db->query('SELECT password_hash FROM users')->fetchColumn()));
                $hash = $db->query('SELECT password_hash FROM users')->fetchColumn();
                Assert::same(self::command($directory, $path, ['--password-stdin'], $password . "\n" . $password . "\n")['exit_code'], 2);
                Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), $hash);
            }
            Assert::same(self::command($directory, '/missing-installation', ['--help'])['exit_code'], 0);
        } finally { $db = null; $directory->close(); }
    }

    #[Test]
    public function selectsActualExistingFileAndRejectsTransientMissingAndForgedInstallations(): void
    {
        $directory = new TemporaryDirectory('tablo selection ж ');
        $db = null;
        try {
            $path = $directory->path . '/space ж.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $db = null;
            $uri = 'file:' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $path)));
            foreach ([$path, 'space ж.sqlite', $uri . '?mode=rw', $uri . '?mode=rw&immutable=false&nolock=0',
                $uri . '?mode=rw&nolock=false&nolock=0&immutable=no&immutable=off',
                $uri . '?mode=rw&mode.x=ro&mode_x=ro&immutable+=1&no.lock=1&nolock_x=1',
                $uri . '?mode=rw#&immutable=1&nolock=1'] as $selection) {
                $result = self::command($directory, $selection, ['--show-installation']);
                Assert::same($result['exit_code'], 0, $result['stderr']);
                Assert::true(str_contains($result['stdout'], 'administrator=1; schema=2'));
                Assert::true(!str_contains($result['stdout'], '?mode='));
                Assert::true(str_contains($result['stdout'], 'space ж.sqlite'));
            }
            foreach ([':memory:', 'file::memory:?cache=shared', 'file:transient?mode=memory', $directory->path . '/missing/sub.sqlite',
                'file:' . $directory->path . '/missing.sqlite?mode=rwc', $uri . '?mode=ro', $uri . '?mode=%72%6f',
                $uri . '?%6dode=memory', $uri . '?mode=rw&immutable=1', $uri . '?%69mmutable=TrUe',
                $uri . '?nolock=1', $uri . '?nolock=2', $uri . '?nolock=unknown', $uri . '?mode=rw%00',
                $uri . '?mode=rw&immutable=0&nolock=on', $uri . '?nolock=0&nolock=1',
                $uri . '?nolock=1&nolock=0', $uri . '?immutable=0&immutable=1', $uri . '?immutable=1&immutable=0',
                $uri . '?mode=rw&mode=ro', $uri . '?mode=ro&mode=rw'] as $selection) {
                Assert::same(self::command($directory, $selection, ['--show-installation'])['exit_code'], 3);
                Assert::true(!file_exists($directory->path . '/missing') && !file_exists($directory->path . '/missing.sqlite'));
            }
            foreach ([0, 1, 3] as $version) {
                $db = new PDO('sqlite:' . $path);
                $db->exec('PRAGMA user_version=' . $version);
                $db = null;
                $before = hash_file('sha256', $path);
                Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
                Assert::same(hash_file('sha256', $path), $before);
            }
            $db = new PDO('sqlite:' . $path);
            $db->exec('PRAGMA user_version=2; ALTER TABLE users RENAME TO original_users;
                CREATE TABLE users(id INTEGER PRIMARY KEY,password_hash TEXT NOT NULL,created_at TEXT NOT NULL);
                INSERT INTO users SELECT * FROM original_users;');
            $db = null;
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
            file_put_contents($directory->path . '/corrupt.sqlite', 'corrupt synthetic file');
            Assert::same(self::command($directory, $directory->path . '/corrupt.sqlite', ['--show-installation'])['exit_code'], 3);
            // Removal after discovery cannot turn the subsequent real READWRITE open into CREATE.
            unlink($path);
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
            Assert::true(!is_file($path));
        } finally { $db = null; $directory->close(); }
    }

    #[Test]
    public function twoActualCommandsCannotOverwriteTheSameSnapshot(): void
    {
        $directory = new TemporaryDirectory('tablo-password-race-');
        $db = null;
        $first = $second = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $uri = 'file:' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $path)));
            foreach ([$path, $uri . '?mode=rw&nolock=0&immutable=false'] as $selection) {
                $first = new PasswordProcess($directory, $selection);
                $second = new PasswordProcess($directory, $selection);
                $first->send("first-new-secret\nfirst-new-secret\n");
                $second->send("second-new-secret\nsecond-new-secret\n");
                $results = [$first->finish()['exit_code'], $second->finish()['exit_code']];
                sort($results);
                Assert::same($results, [0, 4]);
                Assert::same((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 1);
                Assert::true(!(new Auth($db))->needsSetup());
                $first->close(); $second->close(); $first = $second = null;
                Assert::same(self::command($directory, $selection, ['--password-stdin'], "later-new-secret\nlater-new-secret\n")['exit_code'], 0);
                Assert::true((new Auth($db))->login('later-new-secret', 'client'));
            }
        } finally { $first?->close(); $second?->close(); $db = null; $directory->close(); }
    }

    #[Test]
    public function actualUpdateAndCommitBusyFailuresPreserveOldAccess(): void
    {
        $directory = new TemporaryDirectory('tablo-password-storage-');
        $db = null;
        $process = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $db->exec("CREATE TRIGGER block_reset BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'private-trigger-secret'); END");
            $result = self::command($directory, $path, ['--password-stdin'], "replacement-secret\nreplacement-secret\n");
            Assert::same($result['exit_code'], 1);
            Assert::true(!str_contains($result['stderr'], 'private-trigger-secret'));
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            $db->exec('DROP TRIGGER block_reset; BEGIN IMMEDIATE');
            $process = new PasswordProcess($directory, $path);
            $process->send("replacement-secret\nreplacement-secret\n");
            Assert::same($process->finish()['exit_code'], 4, 'failed BEGIN busy');
            $process->close(); $process = null;
            $db->exec('ROLLBACK; BEGIN');
            $db->query('SELECT * FROM users')->fetchAll(); // hold reader: UPDATE succeeds but COMMIT is busy
            $process = new PasswordProcess($directory, $path);
            $process->send("replacement-secret\nreplacement-secret\n");
            Assert::same($process->finish()['exit_code'], 4, 'failed COMMIT busy');
            $process->close(); $process = null;
            $db->exec('ROLLBACK');
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            $process = new PasswordProcess($directory, $path);
            $process->send('');
            Assert::same($process->finish()['exit_code'], 2, 'EOF cancellation');
            Assert::true((new Auth($db))->login('fixture-password', 'old-client'));
            Assert::true(!(new Auth($db))->login('replacement-secret', 'new-client'));
        } finally {
            $process?->close();
            if ($db?->inTransaction()) { $db->rollBack(); }
            $db = null; $directory->close();
        }
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
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            mkdir($directory->path . '/sessions');
            file_put_contents($directory->path . '/sessions/sess_fixture', 'synthetic session bytes');
            Assert::same(self::command($directory, $path, ['--password-stdin'], "replacement-secret\nreplacement-secret\n")['exit_code'], 0);
            Assert::true(!file_exists($directory->path . '/github-token.key'));
            mkdir($directory->path . '/github-token.key'); // unusable as a key file; CLI must not open it
            Assert::same(self::command($directory, $path, ['--password-stdin'], "another-new-secret\nanother-new-secret\n")['exit_code'], 0);
            Assert::true(is_dir($directory->path . '/github-token.key'));
            Assert::same(file_get_contents($directory->path . '/sessions/sess_fixture'), 'synthetic session bytes');
            $db->exec("UPDATE users SET password_hash='not-a-password-hash'");
            $old = $db->query('SELECT * FROM users')->fetchAll();
            Assert::same(self::command($directory, $path, ['--password-stdin'], "replacement-secret\nreplacement-secret\n")['exit_code'], 3);
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } finally { $db = null; $directory->close(); }
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
        } finally { $curl = null; $server?->close(); $directory->close(); }
    }

    #[Test]
    public function actualOpenRefusesAFileRemovedAtResolutionTime(): void
    {
        $directory = new TemporaryDirectory('tablo-file-race-');
        $db = null;
        try {
            $path = $directory->path . '/race.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $db = null;
            $result = Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/fixtures/password-file-race.php', '--show-installation'],
                $directory, ['TABLO_DB' => $path, 'TABLO_RACE_FILE' => $path]);
            Assert::same($result['exit_code'], 3);
            Assert::true(!file_exists($path), 'actual no-create flags survive missing-file race');
        } finally { $db = null; $directory->close(); }
    }

    #[Test]
    public function rechecksSchemaAfterInputAndRejectsZeroRowUpdates(): void
    {
        $directory = new TemporaryDirectory('tablo-password-recheck-');
        $db = null;
        $process = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup('fixture-password', 'fixture-password');
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $process = new PasswordProcess($directory, $path);
            $db->exec('PRAGMA user_version=3');
            $process->send("replacement-secret\nreplacement-secret\n");
            Assert::same($process->finish()['exit_code'], 3, 'schema change after selection refuses as installation error');
            $process->close(); $process = null;
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 3);
            $db->exec("PRAGMA user_version=2; CREATE TRIGGER ignore_reset BEFORE UPDATE ON users BEGIN SELECT RAISE(IGNORE); END");
            Assert::same(self::command($directory, $path, ['--password-stdin'], "replacement-secret\nreplacement-secret\n")['exit_code'], 4);
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            $db->exec('DROP TRIGGER ignore_reset');
            $malformed = substr_replace($old[0]['password_hash'], '00', 4, 2);
            $db->prepare('UPDATE users SET password_hash=?')->execute([$malformed]);
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
        } finally { $process?->close(); $db = null; $directory->close(); }
    }
}
