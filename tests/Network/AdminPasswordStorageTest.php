<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Auth;
use Tablo\Database;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class AdminPasswordStorageTest extends AdminPasswordFixtureCase
{


    #[Test]
    public function actualUpdateRefusalPreservesRowsAndRedactsStorageDiagnostic(): void
    {
        $directory = new TemporaryDirectory('tablo-password-update-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $db->exec("CREATE TRIGGER block_reset BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'private-trigger-secret'); END");
            $result = self::command($directory, $path, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($result['exit_code'], 1);
            Assert::true(!str_contains($result['stderr'], 'private-trigger-secret'));
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
    }



    #[Test]
    public function actualBeginBusyRefusesWithoutOverwritingRows(): void
    {
        $directory = new TemporaryDirectory('tablo-password-begin-');
        $process = null; $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            Assert::same($db->query('PRAGMA journal_mode = DELETE')->fetchColumn(), 'delete');
            $db->exec('BEGIN IMMEDIATE');
            $process = new PasswordProcess($directory, $path);
            $process->send(\Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($process->finish()['exit_code'], 4, 'failed BEGIN busy');
            $db->exec('ROLLBACK');
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->cleanup(static function () use (&$process, &$db, $directory): void {
            $process?->close(); $db = null; $directory->close();
        }); }
    }



    #[Test]
    public function actualCommitBusyInDeleteJournalPreservesRows(): void
    {
        $directory = new TemporaryDirectory('tablo-password-commit-');
        $process = null; $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            Assert::same($db->query('PRAGMA journal_mode = DELETE')->fetchColumn(), 'delete', 'explicit rollback-journal COMMIT-busy control');
            $db->exec('BEGIN');
            $db->query('SELECT * FROM users')->fetchAll(); // hold reader: UPDATE succeeds but COMMIT is busy
            $process = new PasswordProcess($directory, $path);
            $process->send(\Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($process->finish()['exit_code'], 4, 'failed COMMIT busy');
            $process->close(); $process = null;
            $db->exec('ROLLBACK');
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $process?->close(); $db = null; $directory->close(); }
    }



    #[Test]
    public function actualExclusiveOpenIsBusyAndPreservesRowsWithoutPdoDiagnostic(): void
    {
        $directory = new TemporaryDirectory('tablo-password-exclusive-');
        $db = null;
        try {
            $path = $directory->path . '/fixture.sqlite';
            $db = Database::connect($path);
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            Assert::same($db->query('PRAGMA journal_mode = DELETE')->fetchColumn(), 'delete');
            $db->exec('BEGIN EXCLUSIVE');
            $result = self::command($directory, $path, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($result['exit_code'], 4, 'opening an exclusively locked installation is busy');
            Assert::same($result['stdout'], '');
            Assert::true(!str_contains($result['stderr'], 'PDO'));
            $db->exec('ROLLBACK');
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $db = null; $directory->close(); }
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
            (new Auth($db))->setup(\Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'), \Tablo\Tests\Support\FixtureCredentials::value(label: 'fixture-password'));
            $old = $db->query('SELECT * FROM users')->fetchAll();
            $process = new PasswordProcess($directory, $path);
            $db->exec('PRAGMA user_version=' . (Database::CURRENT_SCHEMA_VERSION + 1));
            $process->send(\Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'));
            Assert::same($process->finish()['exit_code'], 3, 'schema change after selection refuses as installation error');
            $process->close(); $process = null;
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION + 1);
            $db->exec('PRAGMA user_version=' . Database::CURRENT_SCHEMA_VERSION . '; CREATE TRIGGER ignore_reset BEFORE UPDATE ON users BEGIN SELECT RAISE(IGNORE); END');
            Assert::same(self::command($directory, $path, ['--password-stdin'], \Tablo\Tests\Support\FixtureCredentials::confirmationInput(label: 'replacement-secret'))['exit_code'], 4);
            Assert::same($db->query('SELECT * FROM users')->fetchAll(), $old);
            $db->exec('DROP TRIGGER ignore_reset');
            $malformed = substr_replace($old[0]['password_hash'], replace: '00', offset: 4, length: 2);
            $db->prepare('UPDATE users SET password_hash=?')->execute([$malformed]);
            Assert::same(self::command($directory, $path, ['--show-installation'])['exit_code'], 3);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $process?->close(); $db = null; $directory->close(); }
    }
}
