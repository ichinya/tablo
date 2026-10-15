<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\MigrationPdo;

use Testo\Assert;
use Testo\Test;

final class NotificationMigrationTest
{
    private static function predecessor(): MigrationPdo
    {
        $db=new MigrationPdo(':memory:'); $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/incident6.sql')));
        return $db;
    }

    #[Test]
    public function eachAdditiveDdlVersionCommitAndConflictingObjectRefusesWithoutPartialWrite(): void
    {
        foreach (['CREATE TABLE notification_settings','CREATE TABLE notification_checkpoints','CREATE TABLE notification_slots',
            'CREATE INDEX notification_due','CREATE INDEX notification_eligible','PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION,'COMMIT'] as $failure) {
            $db=self::predecessor(); $before=\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            $db->failBefore=$failure;
            Assert::instanceOf(IncidentFixtures::error(static fn()=>Database::migrate($db)),\PDOException::class);
            Assert::same((int)\Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(),6);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(),$before);
            $db->failBefore=null; Database::migrate($db);
            Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(),[]);
        }
        foreach (['CREATE TABLE notification_settings(bad TEXT)','CREATE VIEW notification_settings AS SELECT 1',
            'CREATE TABLE notification_checkpoints(bad TEXT)','CREATE VIEW notification_slots AS SELECT 1',
            'CREATE INDEX notification_due ON sites(id)','CREATE INDEX notification_eligible ON sites(id)'] as $conflict) {
            $db=self::predecessor(); $db->exec($conflict);
            $before=\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            Assert::instanceOf(IncidentFixtures::error(static fn()=>Database::migrate($db)),\RuntimeException::class);
            Assert::same((int)\Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(),6);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name'))->fetchAll(),$before);
        }
    }

    #[Test]
    public function additiveSchemaSixUpgradePreservesOldRowsAndIsProspective(): void
    {
        $db=self::predecessor();
        $db->exec("INSERT INTO sites(id,name,url,repository) VALUES(1,'Historical','https://example.test/','fixture/public');
            INSERT INTO check_history(id,site_id,config_revision,checked_at,online,version_status) VALUES(1,1,0,'2026-01-01T00:00:00Z',0,'skipped');
            INSERT INTO incidents(id,site_id,config_revision,opened_at) VALUES(1,1,0,'2026-01-01T00:00:00Z');
            INSERT INTO incident_checkpoints VALUES(1,1,0,'2026-01-01T00:00:00Z')");
        $rows=IncidentFixtures::rows($db);
        Database::migrate($db);
        $after=IncidentFixtures::rows($db);
        foreach ($rows as $table => $values) {
            foreach ($values as $index => $row) { Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($after, $table), $index),$row),$row); }
        }
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_slots'))->fetchAll(),[]);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_checkpoints'))->fetchAll(),[]);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT enabled FROM notification_settings'))->fetchColumn(),0);
        Assert::same((int)\Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(),Database::CURRENT_SCHEMA_VERSION);
        $settings=\Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_settings'))->fetch()); Database::migrate($db);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_settings'))->fetch(),$settings);
        Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(),[]);
        (new \Tablo\SiteRepository($db))->delete(1);
        Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM notification_checkpoints'))->fetchAll(),[]);
    }
}
