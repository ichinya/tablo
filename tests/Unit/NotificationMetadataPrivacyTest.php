<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\NotificationOutbox;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationMetadataPrivacyTest
{


    #[Test]
    public function metadataEditsPreserveRecoveryAndRefreshPendingPrivacy(): void
    {
        foreach (['sent','pending','inflight','unattempted'] as $mode) {
            $f=new F(); $outbox=null;
            try {
                $f->configure(['include_name'=>'1','include_url'=>'1']); $f->accept(0,0); $f->accept(0,60);
                $outbox=new NotificationOutbox($f->db); $claim=null;
                if ($mode!=='unattempted') { $claim=$outbox->claim($f->now); }
                if ($mode==='sent') { Assert::true($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$f->now)); }
                if ($mode==='pending') { Assert::true($outbox->acknowledge($claim,['code'=>'timeout'],$f->now)); }
                $before=\Tablo\Tests\Support\NotificationAssertions::notificationRows($f); $slot=$f->slots()[0];
                $f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'1']);
                $settings=$f->db->query('SELECT * FROM notification_settings')->fetch();
                NotificationPrivacyAssertions::refreshed($f, $before, $slot, $settings, $mode);
                if ($claim!==null) { Assert::false($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$f->now)); }
                $f->accept(1,120); $recoveries=array_values(array_filter($f->slots(),static fn($s)=>$s['event']==='recovery'));
                Assert::same(count($recoveries),$mode==='unattempted'?0:1);
                if ($recoveries!==[]) {
                    Assert::same($recoveries[0]['source_id'],$slot['source_id']);
                    Assert::same(json_decode($f->vault->decrypt($recoveries[0]['private_cipher']),associative: true),['site_origin'=>'https://example.com']);
                }
                $rows=$f->slots(); $f->accept(1,180); Assert::same($f->slots(),$rows);
            } finally { $outbox=null; $f->close(); }
        }
    }



    #[Test]
    public function metadataSaveKeepsDebounceAndOptOutClearsQueuedFields(): void
    {
        $f=new F();
        try {
            $f->configure(); $f->accept(0,0);
            $f->configure(['endpoint'=>'','include_name'=>'1','include_url'=>'1']);
            $f->accept(0,60); Assert::same(count($f->slots()),1,'metadata does not restart confirmation');
            $f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'0']);
            Assert::same($f->slots()[0]['private_cipher'],null); Assert::same($f->slots()[0]['status'],'pending');
        } finally { $f->close(); }
    }
}
