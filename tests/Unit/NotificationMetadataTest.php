<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\NotificationOutbox;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationMetadataTest
{

    #[Test]
    public function unchangedSavePreservesQueueCheckpointAndRecovery(): void
    {
        foreach ([false,true] as $attempt) {
            $f=new F(); $outbox=null;
            try {
                $f->configure(['bearer'=>'synthetic-bearer']); $f->accept(0,0); $f->accept(0,60);
                $outbox=new NotificationOutbox($f->db);
                if ($attempt) { $claim=$outbox->claim($f->now); Assert::true($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$f->now)); }
                $before=\Tablo\Tests\Support\NotificationAssertions::notificationRows($f);
                $f->configure(['endpoint'=>'','bearer'=>'']);
                Assert::same(\Tablo\Tests\Support\NotificationAssertions::notificationRows($f),$before,'blank keep is a genuine no-op');
                $f->accept(1,120); $rows=$f->slots();
                Assert::same(count(array_filter($rows,static fn($s)=>$s['event']==='recovery')),$attempt?1:0);
                $f->accept(1,180); Assert::same($f->slots(),$rows,'recovery is handled once');
            } finally { $outbox=null; $f->close(); }
        }
    }
}
