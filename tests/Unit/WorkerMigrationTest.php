<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class WorkerMigrationTest
{
    #[Test]
    public function migratesAcceptedThreeAtomicallyWithEveryFailureAndPreservedData(): void
    {
        foreach (['ALTER TABLE sites ADD COLUMN config_revision', 'CREATE TABLE installation_settings',
            'INSERT INTO installation_settings', 'CREATE TABLE worker_runtime', 'INSERT INTO worker_runtime',
            'CREATE TABLE worker_progress', 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common3.sql')));
            $db->exec("
                INSERT INTO github_cooldowns VALUES ('saved:1','core',99999);
                INSERT INTO users (id,password_hash) VALUES (1,'synthetic-preserved-hash');
                INSERT INTO sites (name,url,repository,github_token,online,checked_at)
                VALUES ('Existing','https://example.com','example/project','synthetic-cipher',1,'2026-01-01T00:00:00Z')");
            $before = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
                Assert::true(str_contains($error ?? '', 'injected_migration_failure'));
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 3);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch(), $before);
                Assert::same(\Tablo\SqlStatement::checked($db->query("SELECT name FROM sqlite_schema WHERE name = 'worker_progress'"))->fetchColumn(), false);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            $after = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
            Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::fetched($after), \Tablo\Tests\Support\FixtureValue::fetched($before)), $before);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::fetched($after), 'config_revision'), 0);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::fetched($after), 'endpoint_token'), null);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), 'synthetic-preserved-hash');
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT eligible_at FROM github_cooldowns'))->fetchColumn(), 99_999);
            Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
        }
    }
}
