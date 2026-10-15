<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\CheckHistoryRepository;
use Tablo\NotificationOutbox;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationSettingsTest
{









    #[Test]
    public function realChannelChangesRemainProspectiveAndDoNotReplay(): void
    {
        foreach ([['endpoint'=>'https://replacement.example/hook'],['bearer'=>'replacement'],
            ['enabled'=>'0'],['unavailable'=>'0'],['recovery'=>'0'],['version_lag'=>'0']] as $change) {
            $f=new F(); $outbox=null;
            try {
                $f->configure(); $f->accept(0,0); $f->accept(0,60); $outbox=new NotificationOutbox(\Tablo\Tests\Support\FixtureValue::object($f->db));
                $claim=$outbox->claim($f->now); Assert::true($outbox->acknowledge(\Tablo\Tests\Support\FixtureValue::fetched($claim),['code'=>'sent','http_status'=>204],$f->now));
                $f->configure($change+['endpoint'=>'']);
                if (($change['enabled'] ?? null) !== null) { $f->configure(['endpoint'=>'']); }
                Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT activation_id FROM notification_settings'))->fetchColumn(),2);
                $f->accept(1,120); Assert::same(array_column($f->slots(),'event'),['unavailable']);
                $f->accept(0,180); $f->accept(0,240); $f->accept(1,300);
                foreach ($f->slots() as $slot) { if (!($slot['status']==='pending')) { continue; }
 Assert::true($slot['source_id']>2);  }
            } finally { $outbox=null; $f->close(); }
        }
    }

    #[Test]
    public function activationWatermarkSurvivesActualPartialAndFullPruning(): void
    {
        foreach (['retained','partial','full','closed'] as $mode) {
            $f=new F();
            try {
                Assert::true(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query("SELECT sql FROM sqlite_master WHERE name='check_history'"))->fetchColumn()),'AUTOINCREMENT'));
                $f->accept(0,0); $f->accept($mode==='closed'?1:0,60);
                $before=\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...));
                $history=new CheckHistoryRepository(\Tablo\Tests\Support\FixtureValue::object($f->db));
                if ($mode!=='retained') { Assert::same($history->pruneBatch($mode==='partial'?'2026-01-01T00:00:30Z':'2026-01-02T00:00:00Z'),$mode==='partial'?1:2); }
                Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'"))->fetchColumn(),2);
                $f->configure(); Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT activation_id FROM notification_settings'))->fetchColumn(),2);
                Assert::same(\Tablo\SqlRows::all(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT * FROM incidents'))->fetchAll(), \Tablo\SqlIncidentsClockInvalidConfigRevisionEndReasonRow::checked(...)),$before);
                if ($mode!=='closed') { $f->accept(0,120); $f->accept(0,180); Assert::same($f->slots(),[]); $f->accept(1,200); Assert::same($f->slots(),[]); }
                $f->accept(0,240); $f->accept(0,300); Assert::same(count($f->slots()),1);
                Assert::true(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['source_id']>2,'real later incident opening allocated after durable activation');
            } finally { $history=null; $f->close(); }
        }
    }

    #[Test]
    public function freshWatermarkAndPostPruningVersionEvidenceRemainUsable(): void
    {
        $f=new F();
        try {
            Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'"))->fetchColumn(),false);
            $f->configure(); Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT activation_id FROM notification_settings'))->fetchColumn(),0);
            $f->accept(1,0); $f->configure(['endpoint'=>'','enabled'=>'0']);
            Assert::same((new CheckHistoryRepository(\Tablo\Tests\Support\FixtureValue::object($f->db)))->pruneBatch('2026-01-02T00:00:00Z'),1);
            $f->configure(['endpoint'=>'']); Assert::same(\Tablo\SqlStatement::checked(\Tablo\Tests\Support\FixtureValue::object($f->db)->query('SELECT activation_id FROM notification_settings'))->fetchColumn(),1);
            $lag=['version_status'=>'ok','deployed_version'=>'v1.0','latest_release'=>'v2.0','release_error_code'=>null];
            $f->accept(1,60,$lag); $f->accept(1,120,$lag);
            Assert::same(array_column($f->slots(),'event'),['version_lag']); Assert::same(\Tablo\Tests\Support\FixtureValue::at($f->slots(), 0)['source_id'],2);
        } finally { $f->close(); }
    }




















}
