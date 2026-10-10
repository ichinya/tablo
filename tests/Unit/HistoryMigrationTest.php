<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class HistoryMigrationTest
{
    private static function predecessor(): MigrationPdo
    {
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        // Remove the additive v7, v6 and v5 objects to construct the genuine v4 layout.
        $db->exec('DROP TABLE notification_slots; DROP TABLE notification_checkpoints; DROP TABLE notification_settings;
            DROP TABLE incident_checkpoints; DROP TABLE incidents; DROP TABLE check_history; PRAGMA user_version=4');
        $db->exec("INSERT INTO users(id,password_hash) VALUES(1,'synthetic-preserved-hash');
            INSERT INTO git_tokens(name,provider,encrypted_token) VALUES('Saved','github','synthetic-cipher');
            INSERT INTO sites(name,url,repository,git_token_id,online,health_error_code,health_http_status,response_time_ms,
                last_error,checked_at,deployed_version,config_revision)
            VALUES('Old','https://example.com','example/project',1,0,'http',503,12,'old presentation','2025-01-01T00:00:00Z','v1',7);
            INSERT INTO github_cooldowns VALUES('saved:1','core',99999);
            UPDATE installation_settings SET check_interval_minutes=17;
            UPDATE worker_runtime SET fairness_turn=9;
            INSERT INTO worker_progress(site_id,config_revision,latest_release) VALUES(1,7,9)");
        return $db;
    }

    private static function rows(MigrationPdo $db): array
    {
        $rows = [];
        foreach (['users', 'sites', 'git_tokens', 'github_cooldowns', 'installation_settings', 'worker_runtime', 'worker_progress'] as $table) {
            $rows[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
        }
        return $rows;
    }

    #[Test]
    public function historyTransitionRollbackAndTwiceUpgradeKeepEveryPredecessorRow(): void
    {
        foreach (['CREATE TABLE check_history', 'CREATE INDEX check_history_site_time', 'CREATE INDEX check_history_time',
            'PRAGMA main.user_version = 5', 'COMMIT', null] as $failure) {
            $db = self::predecessor();
            $before = self::rows($db);
            $schema = $db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
                Assert::true(str_contains($error ?? '', 'injected_migration_failure'));
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 4);
                Assert::same(self::rows($db), $before);
                Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Database::migrate($db);
            Assert::same(self::rows($db), $before);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0, 'no last-state backfill');
            Assert::same(array_column($db->query('PRAGMA index_list(check_history)')->fetchAll(), 'name'), ['check_history_time', 'check_history_site_time']);
            Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
            $db->statements = [];
            Database::migrate($db);
            Assert::same($db->statements, ['PRAGMA main.user_version']);
        }
    }

    #[Test]
    public function conflictingObjectAndConstraintsFailClosedWithOriginalMigrationError(): void
    {
        foreach (['CREATE TABLE check_history(bad TEXT)', 'CREATE VIEW check_history AS SELECT 1',
            'CREATE INDEX check_history_site_time ON sites(id)', 'CREATE INDEX check_history_time ON sites(id)'] as $conflict) {
            $db = self::predecessor();
            $db->exec($conflict);
            $before = self::rows($db);
            $schema = $db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $error = null;
            try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
            Assert::true($error !== null);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 4);
            Assert::same(self::rows($db), $before);
            Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
        }
        $db = Database::connect(':memory:');
        $db->exec("INSERT INTO sites(name,url,repository) VALUES('Site','https://example.com','example/project')");
        foreach (['2026-02-30T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:00+00:00'] as $time) {
            $error = null;
            try { $db->prepare("INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES(1,0,?,'skipped')")->execute([$time]); }
            catch (\PDOException $caught) { $error = $caught->errorInfo[1]; }
            Assert::same($error, 19);
        }
        Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
    }
}
