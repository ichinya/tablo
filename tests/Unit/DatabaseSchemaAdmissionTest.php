<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;



final class DatabaseSchemaAdmissionTest
{
    #[Test]
    public function rejectedFileSchemasKeepDataVersionAndJournalMetadata(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-rejected-');
        try {
            foreach ([Database::CURRENT_SCHEMA_VERSION + 1, -1, 0] as $version) {
                $path = $directory->path . '/' . $version . '.sqlite';
                $db = new MigrationPdo($path);
                $db->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)');
                $db->exec("INSERT INTO sites VALUES (1, 'preserved')");
                $db->exec('PRAGMA user_version = ' . $version);
                $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
                unset($db);
                Assert::true(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::connect($path)) !== null);
                $db = new MigrationPdo($path);
                Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA journal_mode'))->fetchColumn(), 'delete');
                unset($db);
            }
        } finally {
            unset($db);
            $directory->close();
        }
    }

    #[Test]
    public function currentSchemaValidatesPhysicalLayoutWithoutWritesInsideCallerTransaction(): void
    {
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
        $db->statements = [];
        Database::migrate($db);
        Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
        Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before);
        $db->beginTransaction();
        $db->statements = [];
        Database::migrate($db);
        Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
        Assert::true($db->inTransaction());
        $db->rollBack();
    }

    #[Test]
    public function rejectsFutureOrNegativeVersionsWithoutWrites(): void
    {
        foreach ([Database::CURRENT_SCHEMA_VERSION + 1, -1] as $version) {
            $db = new MigrationPdo(':memory:');
            $db->exec(\Tablo\Tests\Support\DatabaseAssertions::schema());
            $db->exec('PRAGMA user_version = ' . $version);
            $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
            $db->statements = [];
            $message = \Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db));
            Assert::true(str_contains($message ?? '', 'Unsupported SQLite schema version ' . $version));
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
            Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before);
        }
    }

    #[Test]
    public function refusesIncompatibleTablesAndViewsBeforeBootstrap(): void
    {
        foreach (["CREATE VIEW sites AS SELECT 1 AS id", 'CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE users (id INTEGER, password_hash TEXT, created_at TEXT)',
            'CREATE VIEW git_tokens AS SELECT 1 AS id',
            'CREATE TABLE login_limits (key TEXT, failures INTEGER, window_start INTEGER)'] as $sql) {
            $db = new MigrationPdo(':memory:');
            $db->exec($sql);
            $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
            Assert::true(str_contains(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db)) ?? '', 'Incompatible SQLite schema'));
            Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before);
            Assert::false($db->inTransaction());
        }
    }

}
