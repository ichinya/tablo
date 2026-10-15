<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\NotificationOutbox;
use Tablo\SiteProjection;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationSelectionTest
{
    #[Test]
    public function actualIncidentDebounceUnknownRecoveryAndDedupe(): void
    {
        $f=new F();
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,59);
            Assert::same(count($f->slots()),0);
            $f->accept(null,60); $f->accept(0,120); $f->accept(0,179); Assert::same(count($f->slots()),0);
            $f->accept(0,180); $slot=\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0); Assert::same($slot['source_id'],1);
            $f->accept(0,240); Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0),$slot);
            $outbox=new NotificationOutbox(\Tablo\Tests\Support\FixtureValue::object($f->db)); $claim=$outbox->claim($f->now);
            Assert::true($outbox->acknowledge(\Tablo\Tests\Support\FixtureValue::fetched($claim),['code'=>'sent','http_status'=>204],$f->now));
            $f->accept(1,241); Assert::same(count($f->slots()),2);
            $recovered=$f->slots(); $f->accept(1,242); Assert::same($f->slots(),$recovered);
            Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT recovery_history_id FROM incidents'))->fetchColumn(),8);
            \Tablo\Tests\Support\FixtureValue::object($f->db)->exec('DELETE FROM check_history');
            $f->accept(0,300); $f->accept(0,360); Assert::same(count($f->slots()),2);
            Assert::true(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 1)['source_id']>1,'new persisted opening survives pruned timestamp anchors');
        } finally { $outbox=null; $f->close(); }
    }

    #[Test]
    public function earlyRecoverySuppressesPairAndRecoveryOnlyIsImmediate(): void
    {
        $f=new F();
        try {
            $f->configure(); $f->accept(0,0); $f->accept(0,60); $f->accept(1,61);
            Assert::same(count($f->slots()),1); Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['status'],'cancelled');
            $f->configure(['unavailable'=>'0']); $f->accept(0,100); $f->accept(1,101);
            Assert::same(array_column($f->slots(),'event'),['recovery','unavailable']);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['status'],'pending');
        } finally { $f->close(); }
    }

    #[Test]
    public function futureActivationEpochAbaLateEqualAndPauseRemainTruthful(): void
    {
        $f=new F();
        try {
            $f->accept(0,0); $f->configure(); $f->accept(0,60); $f->accept(0,120); Assert::same($f->slots(),[]);
            $snapshot=\Tablo\Tests\Support\FixtureValue::object($f->sites)->find($f->id);
            \Tablo\Tests\Support\FixtureValue::object($f->sites)->save(array_replace(\Tablo\Tests\Support\FixtureValue::row(SiteProjection::withoutEndpointCredential($snapshot)),['enabled'=>0]),$f->id);
            Assert::false(\Tablo\Tests\Support\FixtureValue::object($f->sites)->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot),['checked_at'=>'invalid']));
            \Tablo\Tests\Support\FixtureValue::object($f->sites)->save(array_replace(\Tablo\Tests\Support\FixtureValue::row(SiteProjection::withoutEndpointCredential($snapshot)),['enabled'=>1]),$f->id);
            Assert::false(\Tablo\Tests\Support\FixtureValue::object($f->sites)->storeCheck(\Tablo\Tests\Support\FixtureValue::row($snapshot),['checked_at'=>'invalid']));
            $f->accept(0,200); $f->accept(0,200); Assert::same($f->slots(),[]);
            $f->accept(0,190); $f->accept(0,260); Assert::same($f->slots(),[]);
            $f->accept(0,320); Assert::same(count($f->slots()),1);
            Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query("SELECT end_reason FROM incidents WHERE id=1"))->fetchColumn(),'config-changed');
            Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT recovered_at FROM incidents WHERE id=1'))->fetchColumn(),null);
        } finally { $f->close(); }
    }

    #[Test]
    public function versionKnownComparisonPartialUnknownCatchupResetAndChannelChange(): void
    {
        $f=new F();
        try {
            $f->configure();
            $lag=['version_status'=>'ok','deployed_version'=>'v1.0','latest_release'=>'v2.0','release_error_code'=>null];
            $f->accept(1,0,$lag); $f->accept(1,60,$lag); Assert::same(count($f->slots()),1);
            $key=\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['event_id'];
            $f->configure(['endpoint'=>'https://replacement.example/hook']);
            $f->accept(1,120,$lag); $f->accept(1,180,$lag);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['event_id'],$key); Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['status'],'cancelled');
            $f->accept(1,200,array_replace($lag,['deployed_version'=>'2.0']));
            $f->accept(1,210,$lag); $f->accept(1,270,$lag); Assert::true(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['event_id']!==$key);
            $f->accept(1,280,array_replace($lag,['release_error_code'=>'unavailable','latest_release'=>null]));
            Assert::same(count($f->slots()),1);
        } finally { $f->close(); }
    }
}
