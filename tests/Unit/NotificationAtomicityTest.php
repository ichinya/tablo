<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationAtomicityTest
{
    #[Test]
    public function newConfigurationDiscardsLegacyFieldsButSyntaxFailureRollsBack(): void
    {
        $f = new F();
        try {
            $f->configure(); Assert::true($f->accept(0, 0));
            $db = \Tablo\Tests\Support\FixtureValue::object($f->db);
            $sites = \Tablo\Tests\Support\FixtureValue::object($f->sites);
            foreach (['0', 'null', '{"legacy":"kept until epoch replacement"}'] as $legacy) {
                $statement = \Tablo\SqlStatement::checked($db->prepare('UPDATE notification_checkpoints SET state=?'));
                try { $statement->execute([$legacy]); } finally { $statement->closeCursor(); }
                $sites->save(IncidentFixtures::site(), $f->id);
                Assert::true($f->accept(0, 1));
            }
            $db->exec("UPDATE notification_checkpoints SET state='invalid-json'");
            $sites->save(IncidentFixtures::site(), $f->id);
            $before = IncidentFixtures::rows($db);
            Assert::instanceOf(IncidentFixtures::error(static fn () => $f->accept(0, 2)), \JsonException::class);
            Assert::same(IncidentFixtures::rows($db), $before);
        } finally { unset($statement, $sites, $db); $f->close(); }
    }

    #[Test]
    public function enqueueCheckpointCreditAndCommitFailuresRollBackAllActualOwners(): void
    {
        $f=new F();
        try {
            $f->configure(); $f->accept(0,0);
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('CREATE TABLE commit_control (parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)');
            foreach([
                "BEFORE INSERT ON notification_slots BEGIN SELECT RAISE(ABORT,'slot-control'); END",
                "BEFORE UPDATE ON notification_checkpoints BEGIN SELECT RAISE(ABORT,'checkpoint-control'); END",
                "BEFORE INSERT ON worker_progress BEGIN SELECT RAISE(ABORT,'credit-control'); END",
                'AFTER INSERT ON notification_slots BEGIN INSERT INTO commit_control VALUES(-1); END',
            ] as $trigger){
                $before=IncidentFixtures::rows(\Tablo\Tests\Support\FixtureValue::object($f->db)); $check=\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM notification_checkpoints'))->fetchAll(), \Tablo\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow::checked(...));
                \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('CREATE TRIGGER refusal '.$trigger); $f->now=1060;
                $observedDb = \Tablo\Tests\Support\FixtureValue::object($f->db);
                $observedSite = \Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::object($f->sites)->find($f->id));
                $error=IncidentFixtures::error(static fn()=>(new WorkerStateRepository($observedDb,$f->sites))->settle($observedSite,
                    ['online'=>0,'checked_at'=>'2026-01-01T00:01:00Z','worker_service'=>['latest_release'=>true]]));
                Assert::instanceOf($error,\PDOException::class); Assert::same(IncidentFixtures::rows(\Tablo\Tests\Support\FixtureValue::object($f->db)),$before);
                Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM notification_checkpoints'))->fetchAll(), \Tablo\SqlNotificationCheckpointsConfigRevisionLastHistoryIdSiteIdRow::checked(...)),$check);
                Assert::same($f->slots(),[]); \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('DROP TRIGGER refusal'); unset($error);
            }
            \Tablo\Tests\Support\FixtureValue::object($f->db)->beginTransaction(); \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('UPDATE installation_settings SET check_interval_minutes=7');
            Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->accept(0,60)),\PDOException::class);
            Assert::true(\Tablo\Tests\Support\FixtureValue::object($f->db)->inTransaction()); \Tablo\Tests\Support\FixtureValue::object($f->db)->rollBack();
            Assert::true($f->accept(0,60)); Assert::same(count($f->slots()),1);
        } finally { unset($observedDb, $observedSite, $error); $f->close(); }
    }
}
