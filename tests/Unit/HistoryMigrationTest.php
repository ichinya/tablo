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
        $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common4.sql')));
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

    /** @return array<string, list<array>> */
    private static function rows(MigrationPdo $db): array
    {
        $rows = [];
        foreach (['users', 'sites', 'git_tokens', 'github_cooldowns', 'installation_settings', 'worker_runtime', 'worker_progress'] as $table) {
            $rows[$table] = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM ' . $table . ' ORDER BY 1'))->fetchAll(), \Tablo\JsonRecord::checked(...));
        }
        return $rows;
    }

    #[Test]
    public function historyTransitionRollbackAndTwiceUpgradeKeepEveryPredecessorRow(): void
    {
        foreach (['CREATE TABLE check_history', 'CREATE INDEX check_history_site_time', 'CREATE INDEX check_history_time',
            'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT', null] as $failure) {
            $db = self::predecessor();
            $before = self::rows($db);
            $schema = \Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
                Assert::true(str_contains($error ?? '', 'injected_migration_failure'));
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 4);
                Assert::same(self::rows($db), $before);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $schema);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Database::migrate($db);
            foreach ($before as $table => $rows) {
                foreach ($rows as $index => $row) { Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at(self::rows($db), $table), $index), $row), $row); }
            }
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0, 'no last-state backfill');
            Assert::same(array_map(static fn (array $row): string => \Tablo\SqlColumn::string($row, 'name'), \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('PRAGMA index_list(check_history)'))->fetchAll(), \Tablo\JsonRecord::checked(...))), ['check_history_time', 'check_history_site_time']);
            Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
            $db->statements = [];
            Database::migrate($db);
            Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
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
            $schema = \Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            $error = null;
            try { Database::migrate($db); } catch (\Throwable $caught) { $error = $caught->getMessage(); }
            Assert::true($error !== null);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 4);
            Assert::same(self::rows($db), $before);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $schema);
        }
        $db = Database::connect(':memory:');
        $db->exec("INSERT INTO sites(name,url,repository) VALUES('Site','https://example.com','example/project')");
        foreach (['2026-02-30T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:00+00:00'] as $time) {
            $error = null;
            try { \Tablo\SqlStatement::checked($db->prepare("INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES(1,0,?,'skipped')"))->execute([$time]); }
            catch (\PDOException $caught) { $error = \Tablo\Tests\Support\FixtureNative::sqliteCode($caught); }
            Assert::same($error, 19);
        }
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0);
    }
}
