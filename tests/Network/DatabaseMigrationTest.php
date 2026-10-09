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

final class DatabaseMigrationTest
{
    private static function legacy(string $path, bool $versionOne = false): void
    {
        $db = new MigrationPdo($path);
        $db->exec(file_get_contents(dirname(__DIR__) . ($versionOne ? '/../database/schema.sql' : '/fixtures/pre-json-schema.sql')));
        if ($versionOne) { $db->exec('PRAGMA user_version = 1'); }
        $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash');
            INSERT INTO sites (name,url,repository,github_token) VALUES ('Existing','https://example.com','example/project','synthetic-ciphertext')");
    }

    private static function result(MigrationProcess $process): array
    {
        $result = $process->wait();
        if ($result['code'] !== 0) { throw new RuntimeException('Migration process failed: ' . $result['stderr'] . $result['stdout']); }
        return json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function connectsAndReadsCurrentSchemaWhileAnotherProcessHoldsWriterLock(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-read-');
        $writer = $reader = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash')");
            unset($db);
            $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
            $writer->awaitSignal('writer.locked');
            $reader = new MigrationProcess($directory, 'reader', 'connect', $path);
            $reader->awaitSignal('reader.ready');
            file_put_contents($directory->path . '/start', 'start');
            // The reader must finish before the writer is released, not merely within a timing threshold.
            $result = self::result($reader);
            Assert::same($result['version'], Database::CURRENT_SCHEMA_VERSION);
            Assert::same($result['users'], 1);
            file_put_contents($directory->path . '/writer.release', 'release');
            self::result($writer);
        } finally {
            $reader?->close();
            $writer?->close();
            unset($db);
            $directory->close();
        }
    }

    #[Test]
    public function rechecksVersionAfterWaitingForAnotherMigrator(): void
    {
        foreach (['fresh', 'legacy', 'version-one'] as $schema) {
            $legacy = $schema !== 'fresh';
            $directory = new TemporaryDirectory('tablo-schema-race-');
            $first = $second = null;
            try {
                $path = $directory->path . '/test.sqlite';
                if ($legacy) { self::legacy($path, $schema === 'version-one'); }
                $first = new MigrationProcess($directory, 'first', 'migrate-first', $path);
                $first->awaitSignal('first.locked');
                $second = new MigrationProcess($directory, 'second', 'migrate-second', $path);
                $second->awaitSignal('second.before-begin');
                file_put_contents($directory->path . '/first.release', 'release');
                $a = self::result($first);
                $b = self::result($second);
                Assert::same($a['version'], Database::CURRENT_SCHEMA_VERSION);
                Assert::same($b['version'], Database::CURRENT_SCHEMA_VERSION);
                Assert::true($a['ddl'] > 0);
                Assert::same($b['ddl'], 0, 'Waiting migrator executed bootstrap after the version changed');
                $db = Database::connect($path);
                Assert::same((int) $db->query('SELECT count(*) FROM users')->fetchColumn(), $legacy ? 1 : 0);
                if ($legacy) {
                    Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), 'synthetic-hash');
                    Assert::same($db->query('SELECT github_token FROM sites')->fetchColumn(), 'synthetic-ciphertext');
                }
            } finally {
                $second?->close();
                $first?->close();
                unset($db);
                $directory->close();
            }
        }
    }

    #[Test]
    public function supportsSimultaneousRealConnectCallsOnFreshAndLegacyFiles(): void
    {
        foreach (['fresh', 'legacy', 'version-one'] as $schema) {
            $legacy = $schema !== 'fresh';
            $directory = new TemporaryDirectory('tablo-schema-start-');
            $first = $second = null;
            try {
                $path = $directory->path . '/test.sqlite';
                if ($legacy) { self::legacy($path, $schema === 'version-one'); }
                $first = new MigrationProcess($directory, 'first', 'connect', $path);
                $second = new MigrationProcess($directory, 'second', 'connect', $path);
                $first->awaitSignal('first.ready');
                $second->awaitSignal('second.ready');
                file_put_contents($directory->path . '/start', 'start');
                foreach ([self::result($first), self::result($second)] as $result) {
                    Assert::same($result['version'], Database::CURRENT_SCHEMA_VERSION);
                    Assert::same($result['users'], $legacy ? 1 : 0);
                }
                $db = Database::connect($path);
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
            } finally {
                $second?->close();
                $first?->close();
                unset($db);
                $directory->close();
            }
        }
    }

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
            Assert::same($db->statements, ['PRAGMA main.user_version', 'BEGIN IMMEDIATE']);
            Assert::false($db->inTransaction());
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 0);
            file_put_contents($directory->path . '/writer.release', 'release');
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
        Assert::true(str_contains($message ?? '', 'unable to open database file'));
        Assert::false(is_dir($root));
    }
}
