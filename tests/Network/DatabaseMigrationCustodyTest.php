<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseMigrationCustodyTest extends DatabaseMigrationFixtureCase
{


    #[Test]
    public function lockTimeoutDoesNotRollbackAnotherTransactionAndRetrySucceeds(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-busy-');
        $writer = null;
        try {
            $path = $directory->path . '/test.sqlite';
            self::legacy($path);
            $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
            $writer->awaitSignal('writer.locked');
            $db = new MigrationPdo($path);
            $db->exec('PRAGMA busy_timeout = 100');
            $db->statements = [];
            $message = null;
            try { Database::migrate($db); } catch (\Throwable $error) { $message = $error->getMessage(); }
            unset($error);
            Assert::true(str_contains($message ?? '', 'database is locked'));
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => str_starts_with($sql, 'BEGIN') || str_starts_with($sql, 'ROLLBACK') || str_starts_with($sql, 'COMMIT'))), ['BEGIN IMMEDIATE']);
            Assert::false($db->inTransaction());
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
            file_put_contents($directory->path . '/writer.release', data: 'release');
            self::result($writer);
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same($db->query('SELECT github_token FROM sites')->fetchColumn(), 'synthetic-ciphertext');
        } finally {
            $writer?->close();
            unset($db, $error);
            $directory->close();
        }
    }



    #[Test]
    public function closesWriterAndRemovesFilesAfterScenarioFailure(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-cleanup-');
        $root = $directory->path;
        $writer = null;
        $message = null;
        try {
            try {
                $path = $root . '/test.sqlite';
                $db = Database::connect($path);
                unset($db);
                $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
                $writer->awaitSignal('writer.locked');
                throw new RuntimeException('Simulated migration test failure');
            } finally {
                $writer?->close();
                unset($db);
                $directory->close();
            }
        } catch (RuntimeException $error) { $message = $error->getMessage(); }
        Assert::same($message, 'Simulated migration test failure');
        Assert::false(is_dir($root));
    }



    #[Test]
    public function diagnosesPartialProcessStartupAndCleansUp(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-bad-start-');
        $root = $directory->path;
        $process = null;
        $message = null;
        try {
            $process = new MigrationProcess($directory, 'broken', 'migrate-first', $root . '/missing/test.sqlite');
            try { $process->awaitSignal('broken.locked'); } catch (RuntimeException $error) { $message = $error->getMessage(); }
        } finally {
            $process?->close();
            $directory->close();
        }
        Assert::true(str_contains($message ?? '', '"sqliteCode":14'));
        Assert::false(is_dir($root));
    }
}
