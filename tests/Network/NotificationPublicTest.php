<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\NotificationSettings;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
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
        $directory=new TemporaryDirectory('tablo-notification-public-'); $web=$server=$child=$db=$sites=$settings=$state=null;
        try {
            file_put_contents($directory->path.'/health-status','503');
            $server=new TestServer($directory,dirname(__DIR__).'/fixtures/incident-router.php',environment:['TABLO_WORKER_FIXTURE'=>$directory->path]);
            $web=new WebFixture(true); $csrf=$web->authenticate(); $db=$web->database();
            $sites=new SiteRepository($db);
            foreach(['First','Next'] as $name){Assert::same($web->request('/sites/new',array_replace(F::site(),['_csrf'=>$csrf,'name'=>$name,
                'url'=>$server->base,'repository'=>'fixture/public','health_path'=>'/up','enabled'=>'1']))['status'],303);}
            $settings=new NotificationSettings($db); $settings->update(['revision'=>'0','enabled'=>'1','endpoint'=>'https://127.0.0.1/hook','unavailable'=>'1','recovery'=>'1']);
            // Seed a real accepted opening with independent sample/wall times at least61s ago.
            $sites=new SiteRepository($db,null,static fn():int=>time()-61);
            $sites->storeCheck($sites->find(1),['online'=>0,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-61)]);
            Assert::same($web->request('/sites/1/check',['_csrf'=>$csrf])['status'],303);
            $slot=$db->query('SELECT status,attempts,last_code FROM notification_slots WHERE site_id=1')->fetch();
            Assert::same($slot,['status'=>'failed','attempts'=>1,'last_code'=>'ssrf']);
            Assert::same((int)$db->query('SELECT COUNT(*) FROM check_history WHERE site_id=1')->fetchColumn(),2);
            $root=dirname(__DIR__,2);
            $result=Subprocess::run([PHP_BINARY,'-d','auto_prepend_file='.$root.'/tests/cli-github-fixture.php',$root.'/bin/check.php'],
                $web->directory,['TABLO_DB'=>$web->directory->path.'/test.sqlite','TABLO_ALLOW_PRIVATE_NETWORK'=>'1']);
            Assert::same($result['exit_code'],1); Assert::same((int)$db->query('SELECT COUNT(*) FROM check_history WHERE site_id=2')->fetchColumn(),1);
            file_put_contents($directory->path.'/health-status','200');
            $child=new WorkerProcess($directory,'notification-worker',[PHP_BINARY,$root.'/tests/fixtures/worker-process.php'],
                ['TABLO_DB'=>$web->directory->path.'/test.sqlite','TABLO_WORKER_BASE'=>$server->base]);
            $child->awaitOutput('Worker pass complete.'); $state=new WorkerStateRepository($db); Assert::true($state->requestStop($state->generation()));
            Assert::same($child->wait()['exit_code'],0); Assert::same((int)$db->query('SELECT online FROM sites WHERE id=2')->fetchColumn(),1);
            Assert::same($db->query("SELECT status FROM notification_slots WHERE site_id=1 AND event='recovery'")->fetchColumn(),'failed');
            Assert::true((int)$db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn()===2);
        } finally {$child?->close();$server?->close();$db=$sites=$settings=$state=null;$web?->close();$directory->close();}
    }
}
