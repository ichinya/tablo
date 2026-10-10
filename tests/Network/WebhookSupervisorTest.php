<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\NotificationDelivery;
use Tablo\WebhookSupervisor;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class WebhookSupervisorTest
{
    #[Test]
    public function actualChildSuccessHangingBeforeReadPartialExitAndStopAreFinite(): void
    {
        $root=dirname(__DIR__).'/fixtures/';
        $payload=json_encode(['event_id'=>'fixture:unavailable:1']);
        $ok=(new WebhookSupervisor($root.'webhook-supervisor-ok.php'))->attempt('https://receiver.example/hook','',$payload);
        Assert::same($ok['code'],'sent'); Assert::same($ok['child_exit'],0);
        $started=hrtime(true);
        $hang=(new WebhookSupervisor($root.'webhook-supervisor-hang.php',100))->attempt('https://receiver.example/hook',str_repeat('x',512),str_repeat('x',1024));
        Assert::same($hang['code'],'timeout'); Assert::true($hang['stopped']);
        Assert::true((hrtime(true)-$started)/1e9<2.5,'100ms parent budget plus observed stop grace and OS overhead');
        $partial=(new WebhookSupervisor($root.'webhook-supervisor-partial.php'))->attempt('https://receiver.example/hook','',$payload);
        Assert::same($partial['code'],'child-frame'); Assert::true($partial['stopped']);
        $started=hrtime(true);
        $dns=(new WebhookSupervisor($root.'webhook-supervisor-dns.php',100))->attempt('https://receiver.example/hook','',$payload);
        Assert::same($dns['code'],'timeout'); Assert::true($dns['stopped']);
        Assert::true((hrtime(true)-$started)/1e9<2.5,'actual child resolver seam is covered by parent deadline');
        $count=0;
        $stop=(new WebhookSupervisor($root.'webhook-supervisor-hang.php'))->attempt('https://receiver.example/hook','',$payload,
            static function()use(&$count):bool{return ++$count>=2;});
        Assert::same($stop['code'],'timeout'); Assert::true($stop['stopped']);
        Assert::same((new WebhookSupervisor())->attempt(str_repeat('x',501),'',$payload)['code'],'size');
    }

    #[Test]
    public function deliveryAfterAcceptedCommitHasIndependentFailureAndConditionalAck(): void
    {
        $f=new F(); $delivery=null;
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60);
            $accepted=$f->db->query('SELECT * FROM check_history')->fetchAll();
            $delivery=new NotificationDelivery($f->db,$f->vault,new WebhookSupervisor(dirname(__DIR__).'/fixtures/webhook-supervisor-hang.php',100));
            Assert::same($delivery->runOne(now:$f->now)['code'],'timeout');
            Assert::same($f->db->query('SELECT * FROM check_history')->fetchAll(),$accepted);
            Assert::same($f->slots()[0]['status'],'pending'); Assert::same($f->slots()[0]['attempts'],1);
            $f->db->exec("CREATE TRIGGER ack_refusal BEFORE UPDATE ON notification_slots WHEN OLD.status='inflight'
                BEGIN SELECT RAISE(ABORT,'ack-control'); END");
            $delivery=new NotificationDelivery($f->db,$f->vault,new WebhookSupervisor(dirname(__DIR__).'/fixtures/webhook-supervisor-ok.php'));
            Assert::same($delivery->runOne(now:1120)['code'],'ack-storage');
            Assert::same($f->db->query('SELECT * FROM check_history')->fetchAll(),$accepted);
            $f->db->exec('DROP TRIGGER ack_refusal');
            Assert::true($f->accept(1,121),'next site/check work survives notification ACK failure');
        } finally { $delivery=null; $f->close(); }
    }
}
