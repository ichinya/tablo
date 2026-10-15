<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\NotificationFixture;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class CompositionSettlementTest
{
    #[Test]
    public function everyRedirectReasonPersistsIndependentVersionGitOutcomesAndWorkerCredit(): void
    {
        $db=Database::connect(':memory:'); $sites=new SiteRepository($db); $id=$sites->save(UnitFixtures::site());
        foreach (['redirect-location-missing','redirect-location-invalid','redirect-origin','redirect-cycle','redirect-limit'] as $reason) {
            $http=new FakeHttp([new HttpFailure($reason),new HttpFailure($reason)]);
            $site=$sites->find($id);
            $state=(new SiteChecker($http,new FakeProvider()))->check($site,WorkerStateRepository::FIELDS);
            Assert::same($state['online'],null); Assert::same($state['health_error_code'],$reason);
            Assert::same($state['version_status'],'error'); Assert::same($state['version_error_code'],$reason);
            Assert::same($state['version_http_status'],null); Assert::same($state['latest_release'],'v1.3.2');
            Assert::true((new WorkerStateRepository($db,$sites))->settle($site,$state));
            $row=$db->query('SELECT * FROM check_history ORDER BY id DESC LIMIT 1')->fetch();
            Assert::same($row['health_error_code'],$reason); Assert::same($row['version_error_code'],$reason);
            Assert::same($row['latest_release'],'v1.3.2'); Assert::same($row['online'],null);
            Assert::same((int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn(),0);
        }
        Assert::same((int)$db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(),20);
    }

    #[Test]
    public function metadataBoundaryClassifiesNulUtf8LengthAndSuccessfulVersionBeforeHistory(): void
    {
        $db=Database::connect(':memory:'); $sites=new SiteRepository($db); $id=$sites->save(UnitFixtures::site());
        foreach (["v\0secret",str_repeat('x',times: 201),'v1.2.3'] as $version) {
            $http=new FakeHttp([UnitFixtures::response(),UnitFixtures::response(200,json_encode(['version'=>$version],JSON_THROW_ON_ERROR))]);
            $site=$sites->find($id); $state=(new SiteChecker($http,new FakeProvider()))->check($site);
            Assert::same($state['version_status'],$version==='v1.2.3'?'ok':'error');
            Assert::same($state['version_outcome']['status'],$version==='v1.2.3'?'ok':'failed');
            Assert::same($state['deployed_version'],$version==='v1.2.3'?$version:null);
            Assert::same($state['online'],1); Assert::same($state['latest_release'],'v1.3.2');
            Assert::true($sites->storeCheck($site,$state));
        }
        $http=new FakeHttp([UnitFixtures::response(),UnitFixtures::response(200,"{\"version\":\"\xFF\"}")]);
        $site=$sites->find($id); $state=(new SiteChecker($http,new FakeProvider()))->check($site);
        Assert::same($state['version_status'],'error'); Assert::same($state['deployed_version'],null);
        Assert::true($sites->storeCheck($site,$state));
        Assert::same((int)$db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(),4);
    }

    #[Test]
    public function endpointAbaRejectsEveryProjectionAndCreditAtActualSettlementOwner(): void
    {
        $f=new NotificationFixture();
        try {
            $f->configure(); $f->sites->save([...UnitFixtures::site(),'endpoint_token'=>\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-old')], $f->id);
            $snapshot=$f->sites->find($f->id);
            $f->sites->save([...UnitFixtures::site(),'endpoint_token'=>\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-new')], $f->id);
            $f->sites->save([...UnitFixtures::site(),'endpoint_token'=>\Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-old')], $f->id);
            $before=IncidentFixtures::rows($f->db); $slots=$f->slots();
            Assert::false((new WorkerStateRepository($f->db,$f->sites))->settle($snapshot,
                ['online'=>0,'checked_at'=>'2026-01-01T00:00:00Z','health_error_code'=>'http','worker_service'=>['latest_release'=>true]]));
            Assert::same(IncidentFixtures::rows($f->db),$before); Assert::same($f->slots(),$slots);
            Assert::same($f->db->query('SELECT * FROM notification_checkpoints')->fetchAll(),[]);
        } finally { $f->close(); }
    }

    #[Test]
    public function offlineRedirectCannotEnterNarrowIncidentDomainAndRollsBackCurrentHistoryAndCredit(): void
    {
        $db=Database::connect(':memory:'); $sites=new SiteRepository($db); $id=$sites->save(UnitFixtures::site());
        $before=IncidentFixtures::rows($db);
        $error=IncidentFixtures::error(static fn()=>(new WorkerStateRepository($db,$sites))->settle($sites->find($id),
            ['online'=>0,'checked_at'=>'2026-01-01T00:00:00Z','health_error_code'=>'redirect-origin','worker_service'=>['latest_release'=>true]]));
        Assert::instanceOf($error,\PDOException::class);
        Assert::same(IncidentFixtures::rows($db),$before);
    }
}
