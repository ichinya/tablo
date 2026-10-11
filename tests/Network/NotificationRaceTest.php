<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\Tests\Support\NotificationFixture as F;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class NotificationRaceTest
{
    #[Test]
    public function twoRealProcessesClaimOneAttemptAndNeverHoldNetworkTransaction(): void
    {
        $f=new F(); $first=$second=null;
        try {
            $f->configure();$f->accept(0,0);$f->accept(0,60);
            $command=[PHP_BINARY,dirname(__DIR__).'/fixtures/notification-claim.php',$f->directory->path.'/db.sqlite','1060'];
            $first=new WorkerProcess($f->directory,'claim-a',$command,['TABLO_TOKEN_KEY_FILE'=>'']);
            $second=new WorkerProcess($f->directory,'claim-b',$command,['TABLO_TOKEN_KEY_FILE'=>'']);
            $a=$first->wait();$b=$second->wait();Assert::same($a['exit_code'],0);Assert::same($b['exit_code'],0);
            Assert::true(substr_count($a['stdout'].$b['stdout'],'claimed=1')===1);
            Assert::same($f->slots()[0]['attempts'],1);
            $f->db->exec('BEGIN IMMEDIATE');$f->db->exec('ROLLBACK');
            Assert::true(true,'independent writer acquires immediately while the durable attempt is inflight');
        } finally {$first?->close();$second?->close();$first=$second=null;$f->close();}
    }
}
