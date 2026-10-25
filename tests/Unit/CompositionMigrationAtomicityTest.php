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
            'ALTER TABLE _tablo_history_upgrade', 'CREATE INDEX check_history_site_time', 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT'] as $stage) {
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
            $db->exec('ROLLBACK'); Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name FROM sites'))->fetchColumn(), 'original-site');
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
            $db = $commitFailure ? new class extends \PDO {
                public bool $deferCommitFailure = false;
                public bool $commitFaultReached = false;
                /** @var list<string> */
                public array $statements = [];
                public function __construct()
                {
                    parent::__construct('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
                    $this->exec('PRAGMA foreign_keys=ON');
                }
                public function exec(string $statement): int|false
                {
                    $this->statements[] = $statement;
                    $result = parent::exec($statement);
                    if ($this->deferCommitFailure && $statement === 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION) {
                        $this->commitFaultReached = true;
                        parent::exec('INSERT INTO migration_commit_control VALUES(-1)');
                    }
                    return $result;
                }
            } : new CompositionMigrationPdo();
            $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql')));
            $db->exec("INSERT INTO sites(id,name,url,repository) VALUES(1,'Historical','https://example.test/','fixture/public');
                INSERT INTO check_history(id,site_id,config_revision,checked_at,version_status) VALUES(41,1,0,'2026-01-01T00:00:00Z','skipped');
                UPDATE sqlite_sequence SET seq=900 WHERE name='check_history';
                CREATE TABLE migration_commit_control(parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)");
            $before = self::snapshot($db);
            // Keep the original sequence control. The owned anonymous fixture
            // retargets only the version-sensitive deferred native COMMIT fault.
            if ($db instanceof CompositionMigrationPdo) { $db->refuseSequence = true; }
            if (!($db instanceof CompositionMigrationPdo)) { $db->deferCommitFailure = true; }
            $error = self::error(static fn () => Database::migrate($db));
            Assert::instanceOf($error, \PDOException::class);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::row($error->errorInfo), 1), $commitFailure ? 19 : 1);
            Assert::same(self::snapshot($db), $before);
            if (!($db instanceof CompositionMigrationPdo)) {
                Assert::true($db->commitFaultReached);
                Assert::true(in_array('COMMIT', $db->statements, strict: true));
                Assert::true(in_array('ROLLBACK', $db->statements, strict: true)); $db->deferCommitFailure = false;
            }
            if ($db instanceof CompositionMigrationPdo) { $db->refuseSequence = false; }
            Database::migrate($db);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), \Tablo\Database::CURRENT_SCHEMA_VERSION);
        }
    }
}
