<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;



final class DatabaseMigrationAtomicityTest
{
    #[Test]
    public function rollsBackBootstrapAlterVersionAndCommitFailuresAndAllowsRetry(): void
    {
        foreach (['bootstrap', 'alter', 'version', 'commit'] as $failure) {
            $directory = new TemporaryDirectory('tablo-schema-rollback-');
            try {
                $path = $directory->path . '/test.sqlite';
                $db = new MigrationPdo($path);
                if ($failure !== 'bootstrap') {
                    $db->exec(\Tablo\Tests\Support\DatabaseAssertions::schema(\Tablo\Tests\Support\SchemaFixture::Historic));
                    $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-preserved-hash');
                        INSERT INTO sites (name,url,repository,github_token) VALUES ('Existing','https://example.com','example/project','synthetic-ciphertext')");
                }
                $before = \Tablo\Tests\Support\DatabaseAssertions::snapshot($db);
                $db->failAfterFirstCreate = $failure === 'bootstrap';
                $db->failAfter = match ($failure) {
                    'alter' => 'ALTER TABLE', 'version' => 'PRAGMA main.user_version =', default => null,
                };
                $db->failBefore = $failure === 'commit' ? 'COMMIT' : null;
                $message = \Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db));
                Assert::true(str_contains($message ?? '', 'injected_migration_failure'), $failure . ' did not fail');
                Assert::false($db->inTransaction());
                Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before, $failure . ' left partial data/schema/version');
                unset($db);
                $db = new MigrationPdo($path);
                Assert::same(\Tablo\Tests\Support\DatabaseAssertions::snapshot($db), $before, $failure . ' persisted partial changes');
                Database::migrate($db);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA integrity_check'))->fetchColumn(), 'ok');
            } finally {
                unset($db);
                $directory->close();
            }
        }
    }

    #[Test]
    public function leavesCallerTransactionAndAdditionalTablesUntouched(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->beginTransaction();
        $db->exec('CREATE TABLE notes (value TEXT)');
        $db->exec("INSERT INTO notes VALUES ('caller-owned')");
        $db->statements = [];
        Assert::true(str_contains(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db)) ?? '', 'existing transaction'));
        Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
        Assert::true($db->inTransaction());
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT value FROM notes'))->fetchColumn(), 'caller-owned');
        $db->commit();
        Database::migrate($db);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT value FROM notes'))->fetchColumn(), 'caller-owned');
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
    }

    #[Test]
    public function doesNotEndManuallyStartedCallerTransaction(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->exec('CREATE TABLE notes (value TEXT)');
        $db->exec('BEGIN IMMEDIATE');
        $db->exec("INSERT INTO notes VALUES ('pending')");
        $db->statements = [];
        $message = \Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db));
        Assert::true(str_contains($message ?? '', 'existing transaction') || str_contains($message ?? '', 'within a transaction'));
        Assert::false(in_array('COMMIT', $db->statements, strict: true));
        Assert::false(in_array('ROLLBACK', $db->statements, strict: true));
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT value FROM notes'))->fetchColumn(), 'pending');
        $db->exec('ROLLBACK');
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM notes'))->fetchColumn(), 0);
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 0);
    }

    #[Test]
    public function rollbackFailureDoesNotReplaceMigrationError(): void
    {
        $db = new MigrationPdo(':memory:');
        $db->failRollback = true; $db->failAfterFirstCreate = true;
        $message = \Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::migrate($db));
        Assert::true(str_contains($message ?? '', 'injected_migration_failure'));
        Assert::false(str_contains($message ?? '', 'Injected rollback failure'));
        $db->failRollback = false;
        $db->exec('ROLLBACK');
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 0);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name FROM sqlite_schema'))->fetchAll(), []);
    }

}
