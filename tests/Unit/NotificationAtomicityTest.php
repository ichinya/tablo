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
    public function enqueueCheckpointCreditAndCommitFailuresRollBackAllActualOwners(): void
    {
        $f=new F();
        try {
            $f->configure(); $f->accept(0,0);
            $f->db->exec('CREATE TABLE commit_control (parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)');
            foreach([
                "BEFORE INSERT ON notification_slots BEGIN SELECT RAISE(ABORT,'slot-control'); END",
                "BEFORE UPDATE ON notification_checkpoints BEGIN SELECT RAISE(ABORT,'checkpoint-control'); END",
                "BEFORE INSERT ON worker_progress BEGIN SELECT RAISE(ABORT,'credit-control'); END",
                'AFTER INSERT ON notification_slots BEGIN INSERT INTO commit_control VALUES(-1); END',
            ] as $trigger){
                $before=IncidentFixtures::rows($f->db); $check=$f->db->query('SELECT * FROM notification_checkpoints')->fetchAll();
                $f->db->exec('CREATE TRIGGER refusal '.$trigger); $f->now=1060;
                $error=IncidentFixtures::error(fn()=>(new WorkerStateRepository($f->db,$f->sites))->settle($f->sites->find($f->id),
                    ['online'=>0,'checked_at'=>'2026-01-01T00:01:00Z','worker_service'=>['latest_release'=>true]]));
                Assert::instanceOf($error,\PDOException::class); Assert::same(IncidentFixtures::rows($f->db),$before);
                Assert::same($f->db->query('SELECT * FROM notification_checkpoints')->fetchAll(),$check);
                Assert::same($f->slots(),[]); $f->db->exec('DROP TRIGGER refusal'); unset($error);
            }
            $f->db->beginTransaction(); $f->db->exec('UPDATE installation_settings SET check_interval_minutes=7');
            Assert::instanceOf(IncidentFixtures::error(fn()=>$f->accept(0,60)),\PDOException::class);
            Assert::true($f->db->inTransaction()); $f->db->rollBack();
            Assert::true($f->accept(0,60)); Assert::same(count($f->slots()),1);
        } finally { $f->close(); }
    }
}
