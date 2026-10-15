<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\CompositionMigrationPdo;
use Testo\Assert;
use Testo\Test;

final class CompositionMigrationAtomicityTest extends CompositionSchemaFixtureCase
{


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
            Assert::true(in_array('ROLLBACK', $db->statements, strict: true));
            $db->failBefore = null; Database::migrate($db);
        }
        foreach ([false, true] as $sqlTransaction) {
            $db = self::fixture('history5');
            switch (true) {
case ($sqlTransaction):
 $db->exec('BEGIN IMMEDIATE');
    break;
default:
 $db->beginTransaction();
    break;
}
            $db->exec("UPDATE sites SET name='caller-owned'"); $before = self::snapshot($db); $db->statements = [];
            Assert::instanceOf(self::error(static fn () => Database::migrate($db)), \RuntimeException::class);
            Assert::same(self::snapshot($db), $before);
            Assert::false(in_array('ROLLBACK', $db->statements, strict: true));
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
            $db->deferCommitFailure = false; $db->refuseSequence = false;
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 8);
        }
    }
}
