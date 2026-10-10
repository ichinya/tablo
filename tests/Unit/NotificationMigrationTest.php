<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationMigrationTest
{
    private static function predecessor(): MigrationPdo
    {
        $db=new MigrationPdo(':memory:'); Database::migrate($db);
        $db->exec('DROP TABLE notification_slots; DROP TABLE notification_checkpoints; DROP TABLE notification_settings; PRAGMA user_version=6');
        return $db;
    }

    #[Test]
    public function eachAdditiveDdlVersionCommitAndConflictingObjectRefusesWithoutPartialWrite(): void
    {
        foreach (['CREATE TABLE notification_settings','CREATE TABLE notification_checkpoints','CREATE TABLE notification_slots',
            'CREATE INDEX notification_due','CREATE INDEX notification_eligible','PRAGMA main.user_version = 7','COMMIT'] as $failure) {
            $db=self::predecessor(); $before=$db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $db->failBefore=$failure;
            Assert::instanceOf(IncidentFixtures::error(fn()=>Database::migrate($db)),\PDOException::class);
            Assert::same((int)$db->query('PRAGMA user_version')->fetchColumn(),6);
            Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(),$before);
            $db->failBefore=null; Database::migrate($db);
            Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(),[]);
        }
        foreach (['CREATE TABLE notification_settings(bad TEXT)','CREATE VIEW notification_settings AS SELECT 1',
            'CREATE TABLE notification_checkpoints(bad TEXT)','CREATE VIEW notification_slots AS SELECT 1',
            'CREATE INDEX notification_due ON sites(id)','CREATE INDEX notification_eligible ON sites(id)'] as $conflict) {
            $db=self::predecessor(); $db->exec($conflict);
            $before=$db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            Assert::instanceOf(IncidentFixtures::error(fn()=>Database::migrate($db)),\PDOException::class);
            Assert::same((int)$db->query('PRAGMA user_version')->fetchColumn(),6);
            Assert::same($db->query('SELECT name,type,sql FROM sqlite_schema ORDER BY name')->fetchAll(),$before);
        }
    }

    #[Test]
    public function additiveSchemaSixUpgradePreservesOldRowsAndIsProspective(): void
    {
        $f=new F();
        try {
            $f->accept(0,0); $rows=IncidentFixtures::rows($f->db);
            // Synthetic exact predecessor schema: remove only the three fresh additive tables.
            $f->db->exec('DROP TABLE notification_slots; DROP TABLE notification_checkpoints; DROP TABLE notification_settings; PRAGMA user_version=6');
            Database::migrate($f->db);
            Assert::same(IncidentFixtures::rows($f->db),$rows);
            Assert::same($f->slots(),[]); Assert::same($f->db->query('SELECT * FROM notification_checkpoints')->fetchAll(),[]);
            Assert::same($f->db->query('SELECT enabled FROM notification_settings')->fetchColumn(),0);
            Assert::same((int)$f->db->query('PRAGMA user_version')->fetchColumn(),7);
            $settings=$f->db->query('SELECT * FROM notification_settings')->fetch(); Database::migrate($f->db);
            Assert::same($f->db->query('SELECT * FROM notification_settings')->fetch(),$settings);
            Assert::same($f->db->query('PRAGMA foreign_key_check')->fetchAll(),[]);
            $f->sites->delete($f->id); Assert::same($f->db->query('SELECT * FROM notification_checkpoints')->fetchAll(),[]);
        } finally {$f->close();}
    }
}
