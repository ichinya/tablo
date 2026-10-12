<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\NotificationDelivery;
use Tablo\WebhookSupervisor;
use Tablo\Tests\Support\NotificationFixture as F;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\Subprocess;
use Testo\Assert;
use Testo\Test;

final class WebhookSupervisorTest
{
    #[Test]
    public function actualChildSuccessHangingBeforeReadPartialExitAndStopAreFinite(): void
    {
        $root=dirname(__DIR__).'/fixtures/';
        $payload=json_encode(['event_id'=>'fixture:unavailable:1']);
        $ok=(new WebhookSupervisor($root.'webhook-supervisor-ok.php'))->attempt('https://receiver.example/hook','',\Tablo\Tests\Support\FixtureValue::string($payload));
        Assert::same($ok['code'],'sent'); Assert::same(\Tablo\Tests\Support\FixtureValue::at($ok, 'child_exit'),0);
        $started=\Tablo\MonotonicTime::integer(hrtime(true));
        $hang=(new WebhookSupervisor($root.'webhook-supervisor-hang.php',100))->attempt('https://receiver.example/hook',str_repeat('x',times: 512),str_repeat('x',times: 1024));
        Assert::same($hang['code'],'timeout'); Assert::true($hang['stopped']);
        Assert::true((\Tablo\MonotonicTime::integer(hrtime(true))-$started)/1e9<2.5,'100ms parent budget plus observed stop grace and OS overhead');
        $partial=(new WebhookSupervisor($root.'webhook-supervisor-partial.php'))->attempt('https://receiver.example/hook','',\Tablo\Tests\Support\FixtureValue::string($payload));
        Assert::same($partial['code'],'child-frame'); Assert::true($partial['stopped']);
        $directory=new TemporaryDirectory('tablo-webhook-dns-');
        try {
            $started=\Tablo\MonotonicTime::integer(hrtime(true)); $marker=$directory->path.'/entered';
            $dns=(new WebhookSupervisor($root.'webhook-supervisor-dns.php',500))->attempt('https://receiver.example/hook','',\Tablo\Tests\Support\FixtureValue::string(json_encode(['marker_path'=>$marker])));
            Assert::same($dns['code'],'timeout'); Assert::true($dns['stopped']);
            Assert::true(is_file($marker),'child positively entered actual HTTP resolver seam before deadline');
            Assert::true((\Tablo\MonotonicTime::integer(hrtime(true))-$started)/1e9<2.5,'actual child resolver seam is covered by parent deadline');
        } finally { $directory->close(); }
        $count=new \Tablo\Tests\Support\FixtureCounter();
        $stop=(new WebhookSupervisor($root.'webhook-supervisor-hang.php'))->attempt('https://receiver.example/hook','',\Tablo\Tests\Support\FixtureValue::string($payload),
            static function()use(&$count):bool{return $count->increment()>=2;});
        Assert::same($stop['code'],'timeout'); Assert::true($stop['stopped']);
        Assert::same((new WebhookSupervisor())->attempt(str_repeat('x',times: 501),'',\Tablo\Tests\Support\FixtureValue::string($payload))['code'],'size');
    }

    #[Test]
    public function actualParentShutdownStopsOnlyOwnedChildAndReleasesItsPort(): void
    {
        $directory=new TemporaryDirectory('tablo-webhook-shutdown-'); $socket=null;
        try {
            $errno = 0; $message = '';
            $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
            $port=(int)substr(\Tablo\Tests\Support\FixtureValue::string(strrchr(\Tablo\Tests\Support\FixtureValue::string(stream_socket_get_name(\Tablo\Tests\Support\FixtureNative::resource($socket),remote: false)),needle: ':')),offset: 1); fclose(\Tablo\Tests\Support\FixtureNative::resource($socket)); $socket=null;
            $started=\Tablo\MonotonicTime::integer(hrtime(true));
            $result=Subprocess::run(command: [PHP_BINARY,dirname(__DIR__).'/fixtures/webhook-shutdown-parent.php'], directory: $directory, environment: [], options: new \Tablo\Tests\Support\CommandOptions(input: \Tablo\Tests\Support\FixtureValue::string(json_encode([
                'port'=>$port,'marker_path'=>$directory->path.'/ready']))));
            Assert::same($result['exit_code'],0); Assert::same($result['stderr'],'');
            Assert::true(is_file($directory->path.'/ready'),'owned child listened before parent exit');
            $socket=stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$message);
            Assert::true(is_resource($socket),'shutdown positively releases child listener');
            Assert::true((\Tablo\MonotonicTime::integer(hrtime(true))-$started)/1e9<3.5,'shutdown grace plus measured OS overhead');
        } finally { if(is_resource($socket)){fclose($socket);} $directory->close(); }
    }

    #[Test]
    public function deliveryAfterAcceptedCommitHasIndependentFailureAndConditionalAck(): void
    {
        $f=new F(); $delivery=null;
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60);
            $accepted=\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM check_history'))->fetchAll(), \Tablo\SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow::checked(...));
            $delivery=new NotificationDelivery(\Tablo\Tests\Support\FixtureValue::object($f->db),$f->vault,new WebhookSupervisor(dirname(__DIR__).'/fixtures/webhook-supervisor-hang.php',100));
            Assert::same($delivery->runOne(now:$f->now)['code'],'timeout');
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM check_history'))->fetchAll(), \Tablo\SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow::checked(...)),$accepted);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['status'],'pending'); Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['attempts'],1);
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec("CREATE TRIGGER ack_refusal BEFORE UPDATE ON notification_slots WHEN OLD.status='inflight'
                BEGIN SELECT RAISE(ABORT,'ack-control'); END");
            $delivery=new NotificationDelivery(\Tablo\Tests\Support\FixtureValue::object($f->db),$f->vault,new WebhookSupervisor(dirname(__DIR__).'/fixtures/webhook-supervisor-ok.php'));
            Assert::same($delivery->runOne(now:1120)['code'],'ack-storage');
            Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM check_history'))->fetchAll(), \Tablo\SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow::checked(...)),$accepted);
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('DROP TRIGGER ack_refusal');
            Assert::true($f->accept(1,121),'next site/check work survives notification ACK failure');
        } finally { $delivery=null; $f->close(); }
    }
}
