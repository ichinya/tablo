<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;
use Tablo\SchemaShape;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\CompositionMigrationPdo;
use Testo\Assert;
use Testo\Test;

final class CompositionSchemaTest
{
    private static function fixture(string $name): MigrationPdo
    {
        $db = new MigrationPdo(':memory:');
        $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/' . $name . '.sql'));
        $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'original-admin');
            INSERT INTO sites (id,name,url,repository) VALUES (1,'original-site','https://example.test/','fixture/public')");
        return $db;
    }

    private static function snapshot(PDO $db): array
    {
        $result = ['schema' => SchemaShape::rows($db, 'SELECT type,name,tbl_name,sql FROM sqlite_schema ORDER BY name'),
            'version' => SchemaShape::rows($db, 'PRAGMA user_version')];
        foreach (SchemaShape::objects($db) as $name => $object) {
            if ($object['type'] !== 'table') { continue; }
            $result[$name] = SchemaShape::rows($db, 'SELECT * FROM "' . $name . '" ORDER BY rowid');
        }
        return $result;
    }

    private static function error(callable $action): ?\Throwable
    {
        try { $action(); } catch (\Throwable $error) { return $error; }
        return null;
    }

    #[Test]
    public function independentlyMaterializedLineagesPreserveRowsAndProspectiveActivation(): void
    {
        foreach (['common0', 'common1', 'common2', 'common3', 'common4', 'endpoint5', 'history5', 'incident6', 'notification7'] as $name) {
            $db = self::fixture($name);
            if ($name === 'endpoint5') { $db->exec("UPDATE sites SET endpoint_token='original-cipher', enabled=0"); }
            $before = self::snapshot($db);
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 8, $name);
            foreach ($before as $table => $rows) {
                if (in_array($table, ['schema', 'version', 'sqlite_sequence'], true)) { continue; }
                $actual = SchemaShape::rows($db, 'SELECT * FROM "' . $table . '" ORDER BY rowid');
                foreach ($rows as $index => $row) { Assert::same(array_intersect_key($actual[$index], $row), $row, $name . ':' . $table); }
            }
            Assert::same((int) $db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM incidents')->fetchColumn(), 0);
            Assert::same((int) $db->query('SELECT COUNT(*) FROM notification_slots')->fetchColumn(), 0);
            $db->statements = [];
            $after = self::snapshot($db);
            Database::migrate($db);
            Assert::same(self::snapshot($db), $after);
            Assert::same(array_values(array_filter($db->statements,
                static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT)/', $sql) === 1)), []);
            Assert::same((int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 1);
        }
    }

    #[Test]
    public function historySequenceAndIncidentOutboxIdentitiesSurvivePrunedAndPopulatedUpgrades(): void
    {
        foreach (['history5', 'incident6', 'notification7'] as $name) {
            foreach ([true, false] as $pruned) {
                $db = self::fixture($name);
                $db->exec("INSERT INTO check_history(id,site_id,config_revision,checked_at,online,health_error_code,version_status)
                    VALUES(41,1,0,'2026-01-01T00:00:00Z',0,'http','skipped');
                    UPDATE sqlite_sequence SET seq=900 WHERE name='check_history'");
                if ($pruned) { $db->exec('DELETE FROM check_history'); }
                if ($name !== 'history5') {
                    $db->exec("INSERT INTO incidents(id,site_id,config_revision,opened_at,health_error_code) VALUES(41,1,0,'2026-01-01T00:00:00Z','http');
                        INSERT INTO incident_checkpoints VALUES(1,41,0,'2026-01-01T00:00:00Z')");
                }
                if ($name === 'notification7') {
                    $db->exec("INSERT INTO notification_settings(id,installation_id,activation_id,revision,enabled,blocked,endpoint_cipher,bearer_cipher)
                        VALUES(1,'0123456789abcdef0123456789abcdef',900,4,1,1,'endpoint-original','bearer-original');
                        INSERT INTO notification_checkpoints VALUES(1,41,0,'{\"original\":true}');
                        INSERT INTO notification_slots(site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,
                            created_at,expires_at,due_at,status,attempts,claim,lease_until,last_code,acknowledged_at,coalesced)
                        VALUES(1,'unavailable','original-id',41,0,4,'{\"source\":41}','slot-original',1000,9999,1200,'inflight',2,'original-claim',1500,'original-code',900,3)");
                }
                $before = self::snapshot($db);
                Database::migrate($db);
                foreach (['check_history', 'incidents', 'incident_checkpoints', 'notification_settings', 'notification_checkpoints', 'notification_slots'] as $table) {
                    if (isset($before[$table])) { Assert::same(SchemaShape::rows($db, 'SELECT * FROM ' . $table . ' ORDER BY rowid'), $before[$table], $name); }
                }
                Assert::same((int) $db->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'")->fetchColumn(), 900);
                $db->exec("INSERT INTO check_history(site_id,config_revision,checked_at,version_status) VALUES(1,0,'2026-01-01T00:00:01Z','skipped')");
                Assert::same((int) $db->lastInsertId(), 901);
                Assert::same((int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 1);
            }
        }
    }

    #[Test]
    public function ambiguousCorruptFutureAndUnreviewedDependenciesRefuseBeforeBegin(): void
    {
        $cases = [
            ['common4', 'PRAGMA user_version=5'],
            ['endpoint5', 'CREATE TABLE check_history(fake INTEGER)'],
            ['history5', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['history5', 'DROP INDEX check_history_time'],
            ['history5', 'DROP TABLE check_history; CREATE VIEW check_history AS SELECT 1 AS id'],
            ['endpoint5', 'ALTER TABLE sites DROP COLUMN endpoint_token; ALTER TABLE sites ADD COLUMN endpoint_token INTEGER'],
            ['history5', 'PRAGMA user_version=4'],
            ['incident6', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['notification7', 'ALTER TABLE sites ADD COLUMN endpoint_token TEXT'],
            ['notification7', 'PRAGMA user_version=9'],
            ['history5', 'CREATE TABLE _tablo_history_upgrade(fake INTEGER)'],
            ['history5', 'CREATE TABLE custom_child(id INTEGER REFERENCES check_history(id))'],
            ['history5', 'CREATE INDEX custom_history ON check_history(online)'],
            ['history5', 'CREATE TRIGGER custom_history AFTER INSERT ON check_history BEGIN SELECT 1; END'],
        ];
        foreach ($cases as [$name, $sql]) {
            $db = self::fixture($name);
            $db->exec($sql);
            $before = self::snapshot($db);
            $db->statements = [];
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class, $name . ':' . $sql);
            Assert::false(in_array('BEGIN IMMEDIATE', $db->statements, true));
            Assert::same(self::snapshot($db), $before);
        }
        foreach ([
            ["CHECK (health_error_code IN (", "CHECK (1) /* health_error_code IN ("],
            ['REFERENCES sites(id) ON DELETE CASCADE', 'REFERENCES sites(id) ON DELETE RESTRICT'],
            ['CREATE INDEX check_history_time ON check_history(checked_at, id)', 'CREATE INDEX check_history_time ON check_history(id, checked_at)'],
        ] as [$from, $to]) {
            $sql = file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql');
            if (str_contains($to, '/*')) {
                $sql = preg_replace('/CHECK \(health_error_code IN \([\s\S]*?\)\)/', 'CHECK (1) /* original CHECK omitted */', $sql);
            } else { $sql = str_replace($from, $to, $sql); }
            $db = new MigrationPdo(':memory:'); $db->exec($sql); $before = self::snapshot($db);
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
            Assert::same(self::snapshot($db), $before);
        }
        $db = Database::connect(':memory:'); $db->exec('DROP INDEX notification_due'); $before = self::snapshot($db);
        Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
        Assert::same(self::snapshot($db), $before);
    }

    #[Test]
    public function realSqlFailuresRollbackEveryMigrationStageAndPreserveCallerTransactions(): void
    {
        foreach (['CREATE TABLE _tablo_history_upgrade', 'INSERT INTO _tablo_history_upgrade', 'DROP TABLE check_history',
            'ALTER TABLE _tablo_history_upgrade', 'CREATE INDEX check_history_site_time', 'PRAGMA main.user_version = 8', 'COMMIT'] as $stage) {
            $db = self::fixture('history5'); $before = self::snapshot($db); $db->failBefore = $stage;
            $error = self::error(static fn () => Database::migrate($db));
            Assert::instanceOf($error, \PDOException::class, $stage);
            Assert::true(str_contains($error->getMessage(), 'injected_migration_failure'));
            Assert::same(self::snapshot($db), $before, $stage);
            Assert::true(in_array('ROLLBACK', $db->statements, true));
            $db->failBefore = null; Database::migrate($db);
        }
        foreach ([false, true] as $sqlTransaction) {
            $db = self::fixture('history5');
            if ($sqlTransaction) { $db->exec('BEGIN IMMEDIATE'); } else { $db->beginTransaction(); }
            $db->exec("UPDATE sites SET name='caller-owned'"); $before = self::snapshot($db); $db->statements = [];
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
            Assert::same(self::snapshot($db), $before);
            Assert::false(in_array('ROLLBACK', $db->statements, true));
            $db->exec('ROLLBACK'); Assert::same($db->query('SELECT name FROM sites')->fetchColumn(), 'original-site');
        }
        $db = self::fixture('history5'); $db->afterBegin = static function () use ($db): void { $db->exec('DROP INDEX check_history_time'); };
        $before = self::snapshot($db);
        Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
        Assert::same(self::snapshot($db), $before, 'classifier is rechecked under the owned lock');
    }

    #[Test]
    public function nativeSequenceAndDeferredCommitFailuresRestoreOriginalHistoryAndVersion(): void
    {
        foreach ([false, true] as $commitFailure) {
            $db = new CompositionMigrationPdo();
            $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql'));
            $db->exec("INSERT INTO sites(id,name,url,repository) VALUES(1,'Historical','https://example.test/','fixture/public');
                INSERT INTO check_history(id,site_id,config_revision,checked_at,version_status) VALUES(41,1,0,'2026-01-01T00:00:00Z','skipped');
                UPDATE sqlite_sequence SET seq=900 WHERE name='check_history';
                CREATE TABLE migration_commit_control(parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)");
            $before = self::snapshot($db);
            $db->refuseSequence = !$commitFailure; $db->deferCommitFailure = $commitFailure;
            $error = self::error(static fn () => Database::migrate($db));
            Assert::instanceOf($error, \PDOException::class);
            Assert::same($error->errorInfo[1], $commitFailure ? 19 : 1);
            Assert::same(self::snapshot($db), $before);
            $db->refuseSequence = $db->deferCommitFailure = false;
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 8);
        }
    }
}
