<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\CheckHistoryRepository;
use Tablo\Database;
use Tablo\IncidentRepository;
use Tablo\SiteRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class IncidentMigrationTest
{
    private static function predecessor(): MigrationPdo
    {
        $db = new MigrationPdo(':memory:');
        $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/history5.sql')));
        $db->exec("INSERT INTO sites(id,name,url,repository) VALUES(1,'Historical','https://example.test/','fixture/public');
            INSERT INTO check_history(id,site_id,config_revision,checked_at,online,version_status) VALUES(1,1,0,'2020-01-01T00:00:00Z',0,'skipped')");
        return $db;
    }

    #[Test]
    public function prospectiveUpgradeLeavesOldHistoryReadableAndNeverSeedsFromReadsOrCurrentValues(): void
    {
        $db = self::predecessor();
        $oldSite = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
        $oldHistory = \Tablo\SqlStatement::checked($db->query('SELECT * FROM check_history'))->fetchAll();
        Database::migrate($db);
        Assert::same(array_intersect_key(\Tablo\JsonRecord::checked(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch()), \Tablo\Tests\Support\FixtureValue::fetched($oldSite)), $oldSite);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM check_history'))->fetchAll(), $oldHistory);
        Assert::same((new CheckHistoryRepository($db))->page(1, '2019-01-01T00:00:00Z', '2021-01-01T00:00:00Z')['rows'], $oldHistory);
        $db->exec('PRAGMA query_only=ON');
        Assert::same((new IncidentRepository($db))->page()['rows'], []);
        Database::migrate($db);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM incident_checkpoints'))->fetchAll(), []);
        $db->exec('PRAGMA query_only=OFF');
        F::accept(new SiteRepository($db), 1, 0, '2026-01-01T00:00:00Z');
        Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT id FROM incidents'))->fetchColumn(), 2, 'new accepted identity, never old1');
        $before = F::rows($db);
        Database::migrate($db);
        Assert::same(F::rows($db), $before, 'existing incident/checkpoint unchanged');
        $db->statements = [];
        Database::migrate($db);
        Assert::same(array_values(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|CREATE|ALTER|DROP|INSERT|UPDATE|COMMIT|ROLLBACK)/', $sql) === 1)), []);
    }

    #[Test]
    public function additiveDdlVersionAndCommitFailuresOrConflictsRollBackOriginalSchemaAndData(): void
    {
        foreach (['CREATE TABLE incidents', 'CREATE UNIQUE INDEX incidents_unresolved', 'CREATE INDEX incidents_site_id',
            'CREATE TABLE incident_checkpoints', 'PRAGMA main.user_version = 9', 'COMMIT'] as $failure) {
            $db = self::predecessor();
            $schema = \Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            $site = \Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetchAll();
            $history = \Tablo\SqlStatement::checked($db->query('SELECT * FROM check_history'))->fetchAll();
            $db->failBefore = $failure;
            Assert::instanceOf(F::error(static fn () => Database::migrate($db)), \PDOException::class, $failure);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 5);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $schema);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetchAll(), $site);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM check_history'))->fetchAll(), $history);
            $db->failBefore = null;
            Database::migrate($db);
            Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
        }
        foreach (['CREATE TABLE incidents(bad TEXT)', 'CREATE VIEW incidents AS SELECT 1',
            'CREATE INDEX incidents_unresolved ON sites(id)', 'CREATE INDEX incidents_site_id ON sites(id)',
            'CREATE TABLE incident_checkpoints(bad TEXT)'] as $conflict) {
            $db = self::predecessor();
            $db->exec($conflict);
            $schema = \Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            Assert::instanceOf(F::error(static fn () => Database::migrate($db)), \RuntimeException::class);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 5);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $schema);
        }
    }
}
