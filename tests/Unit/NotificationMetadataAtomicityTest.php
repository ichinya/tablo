<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\NotificationOutbox;
use Tablo\NotificationSettings;
use Tablo\ValidationException;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationMetadataAtomicityTest
{


    #[Test]
    public function noOpAndMetadataRetainConflictCallerTransactionAndAtomicRollback(): void
    {
        $f=new F(); $settings=null;
        try {
            $f->configure(['include_name'=>'1']); $f->accept(0,0); $f->accept(0,60);
            $settings=new NotificationSettings(\Tablo\Tests\Support\FixtureValue::object($f->db),$f->vault); $before=\Tablo\Tests\Support\NotificationAssertions::notificationRows($f);
            Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->configure(['revision'=>'0','endpoint'=>'','include_name'=>'1'])),ValidationException::class);
            Assert::same(\Tablo\Tests\Support\NotificationAssertions::notificationRows($f),$before);
            \Tablo\Tests\Support\FixtureValue::object($f->db)->beginTransaction(); \Tablo\Tests\Support\FixtureValue::object($f->db)->exec("UPDATE sites SET name='caller-owned'");
            Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->configure(['endpoint'=>'','include_name'=>'1'])),\PDOException::class);
            Assert::true(\Tablo\Tests\Support\FixtureValue::object($f->db)->inTransaction()); Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT name FROM sites'))->fetchColumn(),'caller-owned'); \Tablo\Tests\Support\FixtureValue::object($f->db)->rollBack();
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec("CREATE TRIGGER reject_metadata BEFORE UPDATE ON notification_slots BEGIN SELECT RAISE(ABORT,'synthetic mutation refusal'); END");
            Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->configure(['endpoint'=>'','include_name'=>'0'])),\PDOException::class);
            Assert::same(\Tablo\Tests\Support\NotificationAssertions::notificationRows($f),$before); \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('DROP TRIGGER reject_metadata');
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec("CREATE TRIGGER reject_checkpoint BEFORE UPDATE ON notification_checkpoints BEGIN SELECT RAISE(ABORT,'synthetic checkpoint refusal'); END");
            Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->configure(['endpoint'=>'','include_name'=>'0'])),\PDOException::class);
            Assert::same(\Tablo\Tests\Support\NotificationAssertions::notificationRows($f),$before);
        } finally { $settings=null; $f->close(); }
    }



    #[Test]
    public function metadataRebaseSpansThreeBatchesAndRollsBackEveryEarlierMutation(): void
    {
        $f=new F(); $outbox=null;
        try {
            $f->configure(['include_name'=>'1','include_url'=>'1']); $ids=[$f->id];
            for ($i=1;$i<65;$i++) { $ids[]=\Tablo\Tests\Support\FixtureValue::object($f->sites)->save(IncidentFixtures::site()); }
            foreach ($ids as $id) { Assert::true(IncidentFixtures::accept(\Tablo\Tests\Support\FixtureValue::object($f->sites),$id,0,'2026-01-01T00:00:00Z')); }
            $f->now=1060;
            foreach ($ids as $id) { Assert::true(IncidentFixtures::accept(\Tablo\Tests\Support\FixtureValue::object($f->sites),$id,0,'2026-01-01T00:01:00Z')); }
            $outbox=new NotificationOutbox(\Tablo\Tests\Support\FixtureValue::object($f->db)); $oldClaim=$outbox->claim(1060);
            Assert::true($outbox->acknowledge(\Tablo\Tests\Support\FixtureValue::fetched($oldClaim),['code'=>'timeout'],1060));
            $inflight=$outbox->claim(1060); Assert::true(is_array($inflight));
            $before=\Tablo\Tests\Support\NotificationAssertions::notificationRows($f); Assert::same(count($before['notification_slots']),65);
            foreach (['notification_slots','notification_checkpoints'] as $table) {
                \Tablo\Tests\Support\FixtureValue::object($f->db)->exec("CREATE TRIGGER reject_third_batch BEFORE UPDATE ON ".$table." WHEN NEW.site_id=".$ids[64]." BEGIN SELECT RAISE(ABORT,'synthetic third batch refusal'); END");
                Assert::instanceOf(IncidentFixtures::error(static fn()=>$f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'0'])),\PDOException::class);
                Assert::same(\Tablo\Tests\Support\NotificationAssertions::notificationRows($f),$before,'all earlier batches roll back');
                \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('DROP TRIGGER reject_third_batch');
            }
            $f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'0']);
            $after=\Tablo\Tests\Support\NotificationAssertions::notificationRows($f); Assert::same(count($after['notification_slots']),65);
            NotificationRebaseAssertions::allBatches($before, $after);
            Assert::false($outbox->acknowledge(\Tablo\Tests\Support\FixtureValue::fetched($inflight),['code'=>'sent','http_status'=>204],1060));
            Assert::false($outbox->acknowledge(\Tablo\Tests\Support\FixtureValue::fetched($oldClaim),['code'=>'sent','http_status'=>204],1060));
            $f->now=1120; $next=$outbox->claim(1120);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row(\Tablo\Tests\Support\FixtureValue::fetched($next)['slot'])['channel_revision'],\Tablo\Tests\Support\FixtureValue::at($after['notification_settings'], 0)['revision']);
            Assert::same(\Tablo\Tests\Support\FixtureValue::fetched($next)['slot']['private_cipher'],null);
        } finally { $outbox=null; $f->close(); }
    }
}
