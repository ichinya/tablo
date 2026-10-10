<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\NotificationDelivery;
use Tablo\NotificationLock;
use Tablo\NotificationOutbox;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationOutboxTest
{
    #[Test]
    public function expiredBacklogCannotStarveFreshRecoveryAndMaintenanceHasFixedCap(): void
    {
        $f=new F(); $out=null;
        try {
            $f->configure(['unavailable'=>'0','recovery'=>'1','version_lag'=>'0']);
            for($i=0;$i<80;$i++){
                $f->id=$f->sites->save(array_replace(\Tablo\Tests\Support\IncidentFixtures::site(),['name'=>'Synthetic old '.$i]));
                $f->accept(0,0); $f->accept(1,1);
            }
            $f->id=$f->sites->save(\Tablo\Tests\Support\IncidentFixtures::site()); $f->accept(0,5999); $f->accept(1,6000);
            $out=new NotificationOutbox($f->db); $fresh=$out->claim(7000);
            Assert::same($fresh['slot']['site_id'],$f->id); Assert::same($fresh['attempt'],1);
            Assert::same((int)$f->db->query("SELECT count(*) FROM notification_slots WHERE status='expired'")->fetchColumn(),32);
            Assert::true($out->acknowledge($fresh,['code'=>'sent','http_status'=>204],7000));
            Assert::false($out->claim(7000));
            Assert::same((int)$f->db->query("SELECT count(*) FROM notification_slots WHERE status='expired'")->fetchColumn(),64);
            Assert::false($out->claim(7000));
            Assert::same((int)$f->db->query("SELECT count(*) FROM notification_slots WHERE status='expired'")->fetchColumn(),80);
        } finally { $out=null; $f->close(); }
    }

    #[Test]
    public function newerOpeningCoalescesInflightSlotAndFencesItsOldToken(): void
    {
        $f=new F(); $out=null;
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60); $out=new NotificationOutbox($f->db);
            $old=$out->claim(1060); $f->accept(1,61); $f->accept(0,62); $f->accept(0,122);
            $slots=$f->slots(); Assert::same(count($slots),2);
            $unavailable=array_values(array_filter($slots,fn(array $s):bool=>$s['event']==='unavailable'))[0];
            Assert::same($unavailable['coalesced'],1); Assert::same($unavailable['attempts'],0);
            Assert::true($unavailable['event_id']!==$old['slot']['event_id']);
            Assert::false($out->acknowledge($old,['code'=>'sent','http_status'=>204],1122));
        } finally { $out=null; $f->close(); }
    }

    #[Test]
    public function attemptsBackoffLostAckLeaseExpiryStaleAckAndTerminalDedupe(): void
    {
        $f=new F(); $out=null;
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60); $out=new NotificationOutbox($f->db);
            $first=$out->claim(1060); Assert::true(is_array($first)); Assert::false($out->claim(1060));
            Assert::false($out->claim(1090)); // Lease expiry reschedules, never free-retries.
            Assert::false($out->acknowledge($first,['code'=>'sent','http_status'=>204],1091));
            Assert::false($out->claim(1149)); $second=$out->claim(1150);
            Assert::same($second['slot']['event_id'],$first['slot']['event_id']); Assert::same($second['attempt'],2);
            Assert::false($out->acknowledge($first,['code'=>'sent','http_status'=>204],1150));
            Assert::true($out->acknowledge($second,['code'=>'http','http_status'=>503],1150));
            Assert::false($out->claim(1449)); $third=$out->claim(1450); Assert::same($third['attempt'],3);
            Assert::true($out->acknowledge($third,['code'=>'network'],1450)); Assert::false($out->claim(2000));
            Assert::same($f->slots()[0]['status'],'failed');
            $f->accept(0,1100); Assert::same($f->slots()[0]['attempts'],3);
        } finally { $out=null; $f->close(); }
    }

    #[Test]
    public function expiryEpochDeletionAndReplacementNeverResurrectOldClaims(): void
    {
        $f=new F(); $out=null;
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60); $out=new NotificationOutbox($f->db);
            Assert::false($out->claim(4660)); Assert::same($f->slots()[0]['status'],'expired');
            $f->accept(1,70); $f->accept(0,100); $f->accept(0,160); $claim=$out->claim(1160);
            $f->sites->save(array_replace($f->sites->find($f->id),['name'=>'Changed']),$f->id);
            Assert::false($out->acknowledge($claim,['code'=>'sent','http_status'=>204],1161));
            $f->accept(0,200); $f->accept(0,260); $fresh=$out->claim(1260);
            Assert::false($out->acknowledge($claim,['code'=>'sent','http_status'=>204],1261));
            $f->sites->delete($f->id); Assert::false($out->acknowledge($fresh,['code'=>'sent','http_status'=>204],1261));
            Assert::same($f->slots(),[]);
        } finally { $out=null; $f->close(); }
    }

    #[Test]
    public function channelMutexIsNonblockingAndOffDoesNotConstructSender(): void
    {
        $f=new F(); $lock=null; $delivery=null;
        try {
            $delivery=new NotificationDelivery($f->db,$f->vault);
            Assert::same($delivery->runOne()['code'],'off');
            $f->configure(); $lock=new NotificationLock(); Assert::true($lock->acquire($f->db));
            Assert::same($delivery->runOne()['code'],'busy');
            $error=null; try {$f->configure(['enabled'=>'0']);} catch(\Tablo\ValidationException $caught){$error=$caught;}
            Assert::instanceOf($error,\Tablo\ValidationException::class);
            Assert::same((new \Tablo\NotificationSettings($f->db,$f->vault))->get()['enabled'],1);
            $lock->close(); $f->configure(['enabled'=>'0']); Assert::same($delivery->runOne()['code'],'off');
        } finally { $lock?->close(); $lock=$delivery=null; $f->close(); }
    }
}
