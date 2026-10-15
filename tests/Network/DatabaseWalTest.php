<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use PDO;
use RuntimeException;
use Tablo\Database;
use Tablo\GitTokenRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseWalTest
{
    private static function result(MigrationProcess $process): array
    {
        $result = $process->wait();
        Assert::same($result['code'], 0, 'Database process failed: ' . $result['stderr'] . $result['stdout']);
        return json_decode($result['stdout'], associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
    }

    private static function release(TemporaryDirectory $directory, string $name): void
    {
        file_put_contents($directory->path . '/' . $name . '.release', data: 'release');
    }

    private static function assertRestoredRows(PDO $source, PDO $restored, TemporaryDirectory $directory,
        #[\SensitiveParameter] string $adminSecret, #[\SensitiveParameter] string $syntheticToken): void
    {
        foreach (['users', 'sites', 'git_tokens'] as $table) {
            Assert::same($restored->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(),
                $source->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll());
        }
        Assert::same($restored->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
        Assert::same($restored->query('PRAGMA foreign_key_check')->fetchAll(), []);
        Assert::same((int) $restored->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
        Assert::true(password_verify($adminSecret, $restored->query('SELECT password_hash FROM users')->fetchColumn()));
        $restoredVault = TokenVault::forDatabase($restored);
        $encrypted = $restored->query('SELECT encrypted_token FROM git_tokens')->fetchColumn();
        $missingKeyError = null;
        try { $restoredVault->decrypt($encrypted); } catch (RuntimeException $error) { $missingKeyError = $error->getMessage(); }
        unset($error);
        Assert::true($missingKeyError !== null);
        Assert::false(str_contains($missingKeyError, $syntheticToken));
        Assert::false(is_file($directory->path . '/restore/github-token.key'));
        Assert::true(copy($directory->path . '/github-token.key', $directory->path . '/restore/github-token.key'));
        Assert::same($restoredVault->decrypt($encrypted), $syntheticToken);
    }

    #[Test]
    public function writerCommitsBeforeIndependentReaderReleasesSnapshotWithDeleteControl(): void
    {
        foreach (['wal', 'delete'] as $journal) {
            $directory = new TemporaryDirectory('tablo-wal-reader-');
            $reader = null;
            $writer = null;
            try {
                $path = $directory->path . '/test.sqlite';
                $db = Database::connect($path);
                $db->exec("INSERT INTO sites(name,url,repository) VALUES('old','https://example.com','example/project')");
                if ($journal === 'delete') { $db->exec('PRAGMA journal_mode = DELETE'); }
                unset($db);
                $reader = new MigrationProcess($directory, 'reader', $journal === 'wal' ? 'wal-reader' : 'wal-delete-reader', $path);
                $reader->awaitSignal('reader.snapshot');
                $writer = new MigrationProcess($directory, 'writer', $journal === 'wal' ? 'wal-write-short' : 'wal-delete-write', $path);
                // The held reader is released only after the independent writer has exited.
                $write = self::result($writer);
                Assert::same($write['journal'], $journal);
                Assert::same($write['written'], $journal === 'wal');
                if ($journal === 'delete') { Assert::same($write['sqlite_code'], 5); }
                self::release($directory, 'reader');
                $read = self::result($reader);
                Assert::same([$read['before'], $read['during'], $read['after']], [1, 1, $journal === 'wal' ? 2 : 1]);
            } finally {
                $writer?->close();
                $reader?->close();
                unset($db);
                $directory->close();
            }
            Assert::false(is_dir($directory->path));
        }
    }

    #[Test]
    public function defaultWriterWaitIsBoundedAndRetryWorksAfterHolderExits(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-writers-');
        $holder = null;
        $reader = null;
        $writer = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            unset($db);
            $holder = new MigrationProcess($directory, 'holder', 'wal-holder', $path);
            $holder->awaitSignal('holder.locked');
            $reader = new MigrationProcess($directory, 'reader', 'connect', $path);
            $reader->awaitSignal('reader.ready');
            file_put_contents($directory->path . '/start', data: 'start');
            Assert::same(self::result($reader)['journal'], 'wal');
            $writer = new MigrationProcess($directory, 'writer', 'wal-write', $path);
            $write = self::result($writer);
            Assert::same($write['written'], false);
            Assert::same($write['sqlite_code'], 5);
            Assert::same($write['timeout'], 5000);
            Assert::same($write['foreign_keys'], 1);
            Assert::true($write['elapsed'] >= 4.5 && $write['elapsed'] < 8, 'Unexpected default busy wait');
            $db = Database::connect($path);
            Assert::same((int) $db->query('SELECT count(*) FROM sites')->fetchColumn(), 0);
            self::release($directory, 'holder');
            self::result($holder);
            $writer = new MigrationProcess($directory, 'retry', 'wal-write', $path);
            Assert::same(self::result($writer)['written'], true);
            Assert::same((int) $db->query('SELECT count(*) FROM sites')->fetchColumn(), 1);
        } finally {
            $writer?->close();
            $reader?->close();
            $holder?->close();
            unset($db);
            $directory->close();
        }
        Assert::false(is_dir($directory->path));
    }

    #[Test]
    public function backupIncludesCommittedWalAndMatchingKeyWhileCheckpointWaitsForReader(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-backup-');
        $reader = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $db->exec('PRAGMA wal_autocheckpoint = 0'); // Fixture only; product keeps SQLite defaults.
            $adminSecret = bin2hex(random_bytes(16));
            $hash = password_hash($adminSecret, PASSWORD_BCRYPT, options: ['cost' => 4]);
            $db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)')->execute([$hash]);
            $db->exec("INSERT INTO sites(name,url,repository) VALUES('old','https://example.com','example/project')");
            Assert::same($db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM), [0, 0, 0]);
            $reader = new MigrationProcess($directory, 'reader', 'wal-reader', $path);
            $reader->awaitSignal('reader.snapshot');
            $vault = TokenVault::forDatabase($db);
            $syntheticToken = bin2hex(random_bytes(24));
            $tokens = new GitTokenRepository($db, $vault);
            $tokenId = $tokens->save(['name' => 'Synthetic', 'provider' => 'github', 'token' => $syntheticToken]);
            $db->prepare("INSERT INTO sites(name,url,repository,git_token_id) VALUES('new','https://example.com','example/project',?)")
                ->execute([$tokenId]);
            $db->exec('PRAGMA busy_timeout = 150');
            $busy = $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
            Assert::same($busy[0], 1);
            Assert::true($busy[1] > $busy[2], 'Reader must keep uncheckpointed frames');
            mkdir($directory->path . '/restore', permissions: 0o700);
            $backupPath = $directory->path . '/restore/tablo.sqlite';
            // A bare live main-file copy misses the new row. This is deliberately an unsafe control.
            copy($path, $directory->path . '/stale.sqlite');
            $stale = Database::connect($directory->path . '/stale.sqlite');
            Assert::same((int) $stale->query('SELECT count(*) FROM sites')->fetchColumn(), 1);
            unset($stale);
            $db->exec('VACUUM INTO ' . $db->quote($backupPath));
            $restored = Database::connect($backupPath);
            self::assertRestoredRows($db, $restored, $directory, $adminSecret, $syntheticToken);
            self::release($directory, 'reader');
            $read = self::result($reader);
            Assert::same([$read['before'], $read['during'], $read['after']], [1, 1, 2]);
            Assert::same($db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM), [0, 0, 0]);
            clearstatcache(true, $path . '-wal');
            // Runtime/VFS cleanup may remove an empty WAL; a surviving file must be empty.
            Assert::same(is_file($path . '-wal') ? filesize($path . '-wal') : 0, 0);
            // Independently verify checkpointed data in the main file, without source sidecars.
            Assert::true(copy($path, $directory->path . '/checkpoint.sqlite'));
            $checkpointed = Database::connect($directory->path . '/checkpoint.sqlite');
            Assert::same((int) $checkpointed->query('SELECT count(*) FROM sites')->fetchColumn(), 2);
            unset($checkpointed);
        } finally {
            $reader?->close();
            unset($db, $restored, $stale, $checkpointed, $tokens, $vault);
            $directory->close();
        }
        Assert::false(is_dir($directory->path));
    }

    #[Test]
    public function forcedProcessCrashRecoversOnlyCommittedDataFromSurvivingWal(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-crash-');
        $child = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            unset($db);
            $child = new MigrationProcess($directory, 'crash', 'wal-crash', $path);
            $child->awaitSignal('crash.pending');
            Assert::true($child->terminate()['code'] !== 0);
            clearstatcache();
            Assert::true(is_file($path . '-wal'));
            Assert::true(filesize($path . '-wal') > 0);
            $db = Database::connect($path);
            Assert::same($db->query('SELECT name FROM sites ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), ['committed']);
            Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
            Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
        } finally {
            $child?->close();
            unset($db);
            $directory->close();
        }
        Assert::false(is_dir($directory->path));
    }

    #[Test]
    public function scenarioFailureClosesChildrenStatementsAndNestedSidecarsBeforeCleanup(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-cleanup-');
        $reader = null;
        $statement = null;
        $message = null;
        try {
            try {
                mkdir($directory->path . '/nested', permissions: 0o700);
                $path = $directory->path . '/nested/test.sqlite';
                $db = Database::connect($path);
                $db->exec("INSERT INTO sites(name,url,repository) VALUES('old','https://example.com','example/project')");
                $vault = TokenVault::forDatabase($db);
                $vault->encrypt(bin2hex(random_bytes(16)));
                $statement = $db->query('SELECT * FROM sites');
                $reader = new MigrationProcess($directory, 'reader', 'wal-reader', $path);
                $reader->awaitSignal('reader.snapshot');
                Assert::true(is_file($path . '-wal') && is_file($path . '-shm'));
                throw new RuntimeException('Simulated WAL scenario failure');
            } finally {
                $reader?->close(); // Confirms exit, including terminate fallback, before deleting files.
                $statement?->closeCursor();
                unset($statement, $db, $vault);
                $directory->close();
                $directory->close(); // Idempotent after success.
            }
        } catch (RuntimeException $error) { $message = $error->getMessage(); }
        Assert::same($message, 'Simulated WAL scenario failure');
        Assert::false(is_dir($directory->path));
    }

    #[Test]
    public function partialWalStartupReportsPolicyFailureAndClosesProcessBeforeCleanup(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-bad-start-');
        $process = null;
        $message = null;
        try {
            $process = new MigrationProcess($directory, 'broken', 'wal-reader', '');
            try { $process->awaitSignal('broken.snapshot'); } catch (RuntimeException $error) { $message = $error->getMessage(); }
        } finally {
            $process?->close();
            $directory->close();
        }
        Assert::true(str_contains($message ?? '', 'SQLite WAL is required for file databases'));
        Assert::false(is_dir($directory->path));
    }

    #[Test]
    public function retainedStatementPreventsWindowsRemovalUntilReleased(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-handles-');
        try {
            $db = Database::connect($directory->path . '/test.sqlite');
            $statement = $db->query('SELECT * FROM sites');
            unset($db);
            $failure = null;
            try { $directory->close(); } catch (RuntimeException $error) { $failure = $error->getMessage(); }
            unset($error);
            Assert::same($failure !== null, PHP_OS_FAMILY === 'Windows');
            Assert::same(is_dir($directory->path), PHP_OS_FAMILY === 'Windows');
            $statement->closeCursor();
            unset($statement);
            $directory->close();
        } finally {
            unset($db, $statement, $error);
            $directory->close();
        }
        Assert::false(is_dir($directory->path));
    }
}
