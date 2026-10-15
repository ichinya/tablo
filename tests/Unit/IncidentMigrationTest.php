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
        Database::migrate($db);
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        F::accept($sites, $id, 0, '2020-01-01T00:00:00Z');
        // Historical v5 has neither v6 incidents nor v7 notification objects.
        $db->exec('DROP TABLE notification_slots; DROP TABLE notification_checkpoints; DROP TABLE notification_settings;
            DROP TABLE incident_checkpoints; DROP TABLE incidents; PRAGMA user_version=5');
        return $db;
    }

    #[Test]
    public function prospectiveUpgradeLeavesOldHistoryReadableAndNeverSeedsFromReadsOrCurrentValues(): void
    {
        $db = self::predecessor();
        $oldSite = $db->query('SELECT * FROM sites')->fetch();
        $oldHistory = $db->query('SELECT * FROM check_history')->fetchAll();
        Database::migrate($db);
        Assert::same($db->query('SELECT * FROM sites')->fetch(), $oldSite);
        Assert::same($db->query('SELECT * FROM check_history')->fetchAll(), $oldHistory);
        Assert::same((new CheckHistoryRepository($db))->page(1, '2019-01-01T00:00:00Z', '2021-01-01T00:00:00Z')['rows'], $oldHistory);
        $db->exec('PRAGMA query_only=ON');
        Assert::same((new IncidentRepository($db))->page()['rows'], []);
        Database::migrate($db);
        Assert::same($db->query('SELECT * FROM incident_checkpoints')->fetchAll(), []);
        $db->exec('PRAGMA query_only=OFF');
        F::accept(new SiteRepository($db), 1, 0, '2026-01-01T00:00:00Z');
        Assert::same((int) $db->query('SELECT id FROM incidents')->fetchColumn(), 2, 'new accepted identity, never old1');
        $before = F::rows($db);
        Database::migrate($db);
        Assert::same(F::rows($db), $before, 'existing incident/checkpoint unchanged');
        $db->statements = [];
        Database::migrate($db);
        Assert::same($db->statements, ['PRAGMA main.user_version']);
    }

    #[Test]
    public function additiveDdlVersionAndCommitFailuresOrConflictsRollBackOriginalSchemaAndData(): void
    {
        foreach (['CREATE TABLE incidents', 'CREATE UNIQUE INDEX incidents_unresolved', 'CREATE INDEX incidents_site_id',
            'CREATE TABLE incident_checkpoints', 'PRAGMA main.user_version = 6', 'COMMIT'] as $failure) {
            $db = self::predecessor();
            $schema = $db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $site = $db->query('SELECT * FROM sites')->fetchAll();
            $history = $db->query('SELECT * FROM check_history')->fetchAll();
            $db->failBefore = $failure;
            Assert::instanceOf(F::error(fn () => Database::migrate($db)), \PDOException::class, $failure);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 5);
            Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
            Assert::same($db->query('SELECT * FROM sites')->fetchAll(), $site);
            Assert::same($db->query('SELECT * FROM check_history')->fetchAll(), $history);
            $db->failBefore = null;
            Database::migrate($db);
            Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
        }
        foreach (['CREATE TABLE incidents(bad TEXT)', 'CREATE VIEW incidents AS SELECT 1',
            'CREATE INDEX incidents_unresolved ON sites(id)', 'CREATE INDEX incidents_site_id ON sites(id)',
            'CREATE TABLE incident_checkpoints(bad TEXT)'] as $conflict) {
            $db = self::predecessor();
            $db->exec($conflict);
            $schema = $db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            Assert::instanceOf(F::error(fn () => Database::migrate($db)), \PDOException::class);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 5);
            Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
        }
    }
}
