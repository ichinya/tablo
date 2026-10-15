<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\NotificationSettings;
use Tablo\SiteRepository;

use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;
use Testo\Test;

final class NotificationPublicTest
{
    #[Test]
    public function actualManualCliAndWorkerUsePostCommitSenderWithoutCorruptingOtherChecks(): void
    {
        $directory=new TemporaryDirectory('tablo-notification-public-'); $state = null; $settings = null; $sites = null; $db = null; $child = null; $server = null; $web = null;
        try {
            file_put_contents($directory->path.'/health-status',data: '503');
            $server=new TestServer($directory,dirname(__DIR__).'/fixtures/incident-router.php',environment:['TABLO_WORKER_FIXTURE'=>$directory->path]);
            $web=new WebFixture(true); $csrf=$web->authenticate(); $db=$web->database();
            $sites=new SiteRepository($db);
            (new NotificationSiteFixture($web))->create($csrf, $server->base);
            $settings=new NotificationSettings($db); $settings->update(['revision'=>'0','enabled'=>'1','endpoint'=>'https://127.0.0.1/hook','unavailable'=>'1','recovery'=>'1']);
            // Seed a real accepted opening with independent sample/wall times at least61s ago.
            $sites=new SiteRepository($db,null,static fn():int=>time()-61);
            $sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($sites->find(1)),['online'=>0,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-61)]);
            Assert::same($web->request('/sites/1/check',['_csrf'=>$csrf])['status'],303);
            $slot=\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('SELECT status,attempts,last_code FROM notification_slots WHERE site_id=1'))->fetch(), \Tablo\SqlNotificationSlotsAttemptsLastCodeStatusRow::checked(...));
            Assert::same($slot,['status'=>'failed','attempts'=>1,'last_code'=>'ssrf']);
            Assert::same((int)\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history WHERE site_id=1'))->fetchColumn(),2);
            $root=dirname(__DIR__,levels: 2);
            $result=Subprocess::run([PHP_BINARY,'-d','auto_prepend_file='.$root.'/tests/FixtureCliGitHubConnection.php',$root.'/bin/check.php'],
                $web->directory,['TABLO_DB'=>$web->directory->path.'/test.sqlite','TABLO_ALLOW_PRIVATE_NETWORK'=>'1']);
            Assert::same($result['exit_code'],1); Assert::same((int)\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM check_history WHERE site_id=2'))->fetchColumn(),1);
            file_put_contents($directory->path.'/health-status',data: '200');
            $child=new WorkerProcess($directory,'notification-worker',[PHP_BINARY,$root.'/tests/fixtures/worker-process.php'],
                ['TABLO_DB'=>$web->directory->path.'/test.sqlite','TABLO_WORKER_BASE'=>$server->base]);
            (new NotificationWorkerAssertions($child, $db))->completed();
        } finally {$child?->close();$server?->close();$state = null; $settings = null; $sites = null; $db = null;$web?->close();$directory->close();}
    }
}
