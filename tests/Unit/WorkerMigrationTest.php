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
            'CREATE TABLE worker_progress', 'PRAGMA main.user_version = 4', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
            $db->exec("ALTER TABLE sites ADD COLUMN health_error_code TEXT;
                ALTER TABLE sites ADD COLUMN health_http_status INTEGER;
                CREATE TABLE github_cooldowns (scope TEXT,resource TEXT,eligible_at INTEGER,PRIMARY KEY(scope,resource));
                PRAGMA user_version = 3;
                INSERT INTO github_cooldowns VALUES ('saved:1','core',99999);
                INSERT INTO users (id,password_hash) VALUES (1,'synthetic-preserved-hash');
                INSERT INTO sites (name,url,repository,github_token,online,checked_at)
                VALUES ('Existing','https://example.com','example/project','synthetic-cipher',1,'2026-01-01T00:00:00Z')");
            $before = $db->query('SELECT * FROM sites')->fetch();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
                Assert::true(str_contains($error ?? '', 'injected_migration_failure'));
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 3);
                Assert::same($db->query('SELECT * FROM sites')->fetch(), $before);
                Assert::same($db->query("SELECT name FROM sqlite_schema WHERE name = 'worker_progress'")->fetchColumn(), false);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            $after = $db->query('SELECT * FROM sites')->fetch();
            Assert::same(array_intersect_key($after, $before), $before);
            Assert::same($after['config_revision'], 0);
            Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), 'synthetic-preserved-hash');
            Assert::same((int) $db->query('SELECT eligible_at FROM github_cooldowns')->fetchColumn(), 99999);
            Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
        }
    }
}
