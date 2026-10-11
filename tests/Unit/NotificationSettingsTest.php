<?php
declare(strict_types=1);
namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\CheckHistoryRepository;
use Tablo\NotificationOutbox;
use Tablo\NotificationSettings;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\Tests\Support\NotificationFixture as F;
use Testo\Assert;
use Testo\Test;

final class NotificationSettingsTest
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
                $before=self::notificationRows($f);
                $f->configure(['endpoint'=>'','bearer'=>'']);
                Assert::same(self::notificationRows($f),$before,'blank keep is a genuine no-op');
                $f->accept(1,120); $rows=$f->slots();
                Assert::same(count(array_filter($rows,fn($s)=>$s['event']==='recovery')),$attempt?1:0);
                $f->accept(1,180); Assert::same($f->slots(),$rows,'recovery is handled once');
            } finally { $outbox=null; $f->close(); }
        }
    }

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
                $before=self::notificationRows($f); $slot=$f->slots()[0];
                $f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'1']);
                $settings=$f->db->query('SELECT * FROM notification_settings')->fetch();
                Assert::same($settings['activation_id'],$before['notification_settings'][0]['activation_id']);
                Assert::same($settings['revision'],$before['notification_settings'][0]['revision']+1);
                Assert::same($settings['endpoint_cipher'],$before['notification_settings'][0]['endpoint_cipher']);
                $updated=$f->slots()[0]; Assert::same($updated['event_id'],$slot['event_id']);
                Assert::same($updated['attempts'],$slot['attempts']);
                if (in_array($mode,['pending','unattempted'],true)) {
                    Assert::same($updated['status'],'pending'); Assert::same($updated['channel_revision'],$settings['revision']);
                    Assert::same(json_decode($f->vault->decrypt($updated['private_cipher']),true),['site_origin'=>'https://example.com']);
                    Assert::same($updated['due_at'],$slot['due_at']); Assert::same($updated['expires_at'],$slot['expires_at']);
                }
                if ($mode==='inflight') { Assert::same($updated['status'],'cancelled'); Assert::same($updated['private_cipher'],null); }
                if ($claim!==null) { Assert::false($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$f->now)); }
                $f->accept(1,120); $recoveries=array_values(array_filter($f->slots(),fn($s)=>$s['event']==='recovery'));
                Assert::same(count($recoveries),$mode==='unattempted'?0:1);
                if ($recoveries!==[]) {
                    Assert::same($recoveries[0]['source_id'],$slot['source_id']);
                    Assert::same(json_decode($f->vault->decrypt($recoveries[0]['private_cipher']),true),['site_origin'=>'https://example.com']);
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

    #[Test]
    public function realChannelChangesRemainProspectiveAndDoNotReplay(): void
    {
        foreach ([['endpoint'=>'https://replacement.example/hook'],['bearer'=>'replacement'],
            ['enabled'=>'0'],['unavailable'=>'0'],['recovery'=>'0'],['version_lag'=>'0']] as $change) {
            $f=new F(); $outbox=null;
            try {
                $f->configure(); $f->accept(0,0); $f->accept(0,60); $outbox=new NotificationOutbox($f->db);
                $claim=$outbox->claim($f->now); Assert::true($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$f->now));
                $f->configure($change+['endpoint'=>'']);
                if (isset($change['enabled'])) { $f->configure(['endpoint'=>'']); }
                Assert::same($f->db->query('SELECT activation_id FROM notification_settings')->fetchColumn(),2);
                $f->accept(1,120); Assert::same(array_column($f->slots(),'event'),['unavailable']);
                $f->accept(0,180); $f->accept(0,240); $f->accept(1,300);
                foreach ($f->slots() as $slot) { if ($slot['status']==='pending') { Assert::true($slot['source_id']>2); } }
            } finally { $outbox=null; $f->close(); }
        }
    }

    #[Test]
    public function activationWatermarkSurvivesActualPartialAndFullPruning(): void
    {
        foreach (['retained','partial','full','closed'] as $mode) {
            $f=new F();
            try {
                Assert::true(str_contains($f->db->query("SELECT sql FROM sqlite_master WHERE name='check_history'")->fetchColumn(),'AUTOINCREMENT'));
                $f->accept(0,0); $f->accept($mode==='closed'?1:0,60);
                $before=$f->db->query('SELECT * FROM incidents')->fetchAll();
                $history=new CheckHistoryRepository($f->db);
                if ($mode!=='retained') { Assert::same($history->pruneBatch($mode==='partial'?'2026-01-01T00:00:30Z':'2026-01-02T00:00:00Z'),$mode==='partial'?1:2); }
                Assert::same($f->db->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'")->fetchColumn(),2);
                $f->configure(); Assert::same($f->db->query('SELECT activation_id FROM notification_settings')->fetchColumn(),2);
                Assert::same($f->db->query('SELECT * FROM incidents')->fetchAll(),$before);
                if ($mode!=='closed') { $f->accept(0,120); $f->accept(0,180); Assert::same($f->slots(),[]); $f->accept(1,200); Assert::same($f->slots(),[]); }
                $f->accept(0,240); $f->accept(0,300); Assert::same(count($f->slots()),1);
                Assert::true($f->slots()[0]['source_id']>2,'real later incident opening allocated after durable activation');
            } finally { $history=null; $f->close(); }
        }
    }

    #[Test]
    public function freshWatermarkAndPostPruningVersionEvidenceRemainUsable(): void
    {
        $f=new F();
        try {
            Assert::same($f->db->query("SELECT seq FROM sqlite_sequence WHERE name='check_history'")->fetchColumn(),false);
            $f->configure(); Assert::same($f->db->query('SELECT activation_id FROM notification_settings')->fetchColumn(),0);
            $f->accept(1,0); $f->configure(['endpoint'=>'','enabled'=>'0']);
            Assert::same((new CheckHistoryRepository($f->db))->pruneBatch('2026-01-02T00:00:00Z'),1);
            $f->configure(['endpoint'=>'']); Assert::same($f->db->query('SELECT activation_id FROM notification_settings')->fetchColumn(),1);
            $lag=['version_status'=>'ok','deployed_version'=>'v1.0','latest_release'=>'v2.0','release_error_code'=>null];
            $f->accept(1,60,$lag); $f->accept(1,120,$lag);
            Assert::same(array_column($f->slots(),'event'),['version_lag']); Assert::same($f->slots()[0]['source_id'],2);
        } finally { $f->close(); }
    }

    #[Test]
    public function noOpAndMetadataRetainConflictCallerTransactionAndAtomicRollback(): void
    {
        $f=new F(); $settings=null;
        try {
            $f->configure(['include_name'=>'1']); $f->accept(0,0); $f->accept(0,60);
            $settings=new NotificationSettings($f->db,$f->vault); $before=self::notificationRows($f);
            Assert::instanceOf(IncidentFixtures::error(fn()=>$f->configure(['revision'=>'0','endpoint'=>'','include_name'=>'1'])),ValidationException::class);
            Assert::same(self::notificationRows($f),$before);
            $f->db->beginTransaction(); $f->db->exec("UPDATE sites SET name='caller-owned'");
            Assert::instanceOf(IncidentFixtures::error(fn()=>$f->configure(['endpoint'=>'','include_name'=>'1'])),\PDOException::class);
            Assert::true($f->db->inTransaction()); Assert::same($f->db->query('SELECT name FROM sites')->fetchColumn(),'caller-owned'); $f->db->rollBack();
            $f->db->exec("CREATE TRIGGER reject_metadata BEFORE UPDATE ON notification_slots BEGIN SELECT RAISE(ABORT,'synthetic mutation refusal'); END");
            Assert::instanceOf(IncidentFixtures::error(fn()=>$f->configure(['endpoint'=>'','include_name'=>'0'])),\PDOException::class);
            Assert::same(self::notificationRows($f),$before); $f->db->exec('DROP TRIGGER reject_metadata');
            $f->db->exec("CREATE TRIGGER reject_checkpoint BEFORE UPDATE ON notification_checkpoints BEGIN SELECT RAISE(ABORT,'synthetic checkpoint refusal'); END");
            Assert::instanceOf(IncidentFixtures::error(fn()=>$f->configure(['endpoint'=>'','include_name'=>'0'])),\PDOException::class);
            Assert::same(self::notificationRows($f),$before);
        } finally { $settings=null; $f->close(); }
    }

    private static function notificationRows(F $f): array
    {
        $rows=[];
        foreach (['notification_settings','notification_slots','notification_checkpoints'] as $table) { $rows[$table]=$f->db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(); }
        return $rows;
    }

    #[Test]
    public function metadataRebaseSpansThreeBatchesAndRollsBackEveryEarlierMutation(): void
    {
        $f=new F(); $outbox=null;
        try {
            $f->configure(['include_name'=>'1','include_url'=>'1']); $ids=[$f->id];
            for ($i=1;$i<65;$i++) { $ids[]=$f->sites->save(IncidentFixtures::site()); }
            foreach ($ids as $id) { Assert::true(IncidentFixtures::accept($f->sites,$id,0,'2026-01-01T00:00:00Z')); }
            $f->now=1060;
            foreach ($ids as $id) { Assert::true(IncidentFixtures::accept($f->sites,$id,0,'2026-01-01T00:01:00Z')); }
            $outbox=new NotificationOutbox($f->db); $oldClaim=$outbox->claim(1060);
            Assert::true($outbox->acknowledge($oldClaim,['code'=>'timeout'],1060));
            $inflight=$outbox->claim(1060); Assert::true(is_array($inflight));
            $before=self::notificationRows($f); Assert::same(count($before['notification_slots']),65);
            foreach (['notification_slots','notification_checkpoints'] as $table) {
                $f->db->exec("CREATE TRIGGER reject_third_batch BEFORE UPDATE ON ".$table." WHEN NEW.site_id=".$ids[64]." BEGIN SELECT RAISE(ABORT,'synthetic third batch refusal'); END");
                Assert::instanceOf(IncidentFixtures::error(fn()=>$f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'0'])),\PDOException::class);
                Assert::same(self::notificationRows($f),$before,'all earlier batches roll back');
                $f->db->exec('DROP TRIGGER reject_third_batch');
            }
            $f->configure(['endpoint'=>'','include_name'=>'0','include_url'=>'0']);
            $after=self::notificationRows($f); Assert::same(count($after['notification_slots']),65);
            foreach ($after['notification_slots'] as $i=>$row) {
                $old=$before['notification_slots'][$i];
                foreach (['event_id','source_id','attempts','created_at','due_at','expires_at','coalesced'] as $key) { Assert::same($row[$key],$old[$key]); }
                Assert::same($row['private_cipher'],null);
                Assert::same($row['status'],$old['status']==='inflight'?'cancelled':'pending');
                if ($row['status']==='pending') { Assert::same($row['channel_revision'],$after['notification_settings'][0]['revision']); }
            }
            foreach ($after['notification_checkpoints'] as $i=>$row) {
                $old=$before['notification_checkpoints'][$i]; $state=json_decode($old['state'],true);
                $state['channel']=$after['notification_settings'][0]['revision'];
                Assert::same(json_decode($row['state'],true),$state);
                Assert::same($row['last_history_id'],$old['last_history_id']); Assert::same($row['config_revision'],$old['config_revision']);
            }
            Assert::false($outbox->acknowledge($inflight,['code'=>'sent','http_status'=>204],1060));
            Assert::false($outbox->acknowledge($oldClaim,['code'=>'sent','http_status'=>204],1060));
            $f->now=1120; $next=$outbox->claim(1120);
            Assert::same($next['slot']['channel_revision'],$after['notification_settings'][0]['revision']);
            Assert::same($next['slot']['private_cipher'],null);
        } finally { $outbox=null; $f->close(); }
    }

    #[Test]
    public function bearerAndPrivateSlotAloneAuthenticateExternalStartupWitness(): void
    {
        foreach (['bearer','private'] as $only) {
            $f=new F(); $db=$external=null;
            try {
                $f->configure(['bearer'=>bin2hex(random_bytes(24)),'include_name'=>'1']);
                $f->accept(0,0); $f->accept(0,60);
                $f->db->exec('UPDATE notification_settings SET enabled=0,endpoint_cipher=NULL'.($only==='private'?',bearer_cipher=NULL':''));
                if ($only==='bearer') { $f->db->exec('UPDATE notification_slots SET private_cipher=NULL'); }
                Assert::true(SiteRepository::hasEstablishedKeyState($f->db));
                $key=$f->directory->path.'/github-token.key'; $path=$f->directory->path.'/db.sqlite';
                $f->sites=$f->vault=$f->db=null;
                $external=new TokenVault($key,true); $db=Database::connect($path,$external);
                Assert::same((int)$db->query('PRAGMA user_version')->fetchColumn(),7);
                $db=$external=null; file_put_contents($key,random_bytes(32)); $external=new TokenVault($key,true);
                Assert::instanceOf(IncidentFixtures::error(fn()=>Database::connect($path,$external)),SharedKeyFailure::class);
            } finally { $db=$external=null; $f->close(); }
        }
    }

    #[Test]
    public function secretsEncryptedBlankKeepRemoveConflictTypeAndLengthSafe(): void
    {
        $f=new F(); $settings=null;
        try {
            $secret=bin2hex(random_bytes(24)); $settings=new NotificationSettings($f->db,$f->vault);
            $f->configure(['bearer'=>$secret]); $before=$f->db->query('SELECT * FROM notification_settings')->fetch();
            Assert::true($before['bearer_cipher']!==$secret);
            Assert::true($f->vault->decrypt($before['bearer_cipher'])===$secret);
            Assert::false(str_contains(json_encode($settings->get()),$secret));
            $f->configure(['endpoint'=>'','bearer'=>'']); $kept=$f->db->query('SELECT * FROM notification_settings')->fetch();
            Assert::true($kept['bearer_cipher']===$before['bearer_cipher'] && $kept['endpoint_cipher']===$before['endpoint_cipher']);
            foreach([['revision'=>'0'],['endpoint'=>[]],['bearer'=>[]],['bearer'=>str_repeat('x',513)],
                ['endpoint'=>'http://receiver.example/hook'],['endpoint'=>'https://receiver.example/'.str_repeat('x',501)],
                ['enabled'=>[]],['unavailable'=>'2'],['endpoint'=>'https://receiver.example/hook','remove_endpoint'=>'1']] as $bad){
                $error=IncidentFixtures::error(fn()=>$f->configure($bad)); Assert::instanceOf($error,ValidationException::class);
                Assert::false(str_contains($error->getMessage(),$secret)); unset($error);
            }
            $f->configure(['enabled'=>'0','endpoint'=>'','bearer'=>'','remove_endpoint'=>'1','remove_bearer'=>'1']);
            Assert::same($settings->get()['endpoint_present'],0); Assert::same($settings->get()['bearer_present'],0);
        } finally { $settings=null; $f->close(); }
    }

    #[Test]
    public function endpointOnlyIsEstablishedWitnessAndWrongKeyFailsBeforeWrites(): void
    {
        $f=new F(); $db=$external=$settings=null;
        try {
            $f->configure(); Assert::true(SiteRepository::hasEstablishedKeyState($f->db));
            $before=$f->db->query('SELECT * FROM notification_settings')->fetch();
            $key=$f->directory->path.'/github-token.key'; $original=file_get_contents($key);
            $path=$f->directory->path.'/db.sqlite';
            $f->sites=$f->vault=$f->db=null;
            $external=new TokenVault($key,true); $db=Database::connect($path,$external);
            Assert::true($db->query('SELECT endpoint_cipher FROM notification_settings')->fetchColumn()===$before['endpoint_cipher']);
            $db=$external=null; file_put_contents($key,random_bytes(32)); $external=new TokenVault($key,true);
            Assert::instanceOf(IncidentFixtures::error(fn()=>Database::connect($path,$external)),SharedKeyFailure::class);
            $external=null; file_put_contents($key,$original);
            $db=Database::connect($path); $settings=new NotificationSettings($db,new TokenVault($key));
            unlink($key); Assert::instanceOf(IncidentFixtures::error(fn()=>$settings->update(['revision'=>'1','endpoint'=>'https://replacement.example/hook'])),SharedKeyFailure::class);
            Assert::false(is_file($key)); Assert::true($db->query('SELECT endpoint_cipher FROM notification_settings')->fetchColumn()===$before['endpoint_cipher']);
        } finally { $db=$external=$settings=null; $f->close(); }
    }

    #[Test]
    public function optInPrivatePayloadIsEncryptedAndOriginOnly(): void
    {
        $f=new F();
        try {
            $f->sites->save(array_replace($f->sites->find($f->id),['name'=>'<private-name>','url'=>'https://example.com/private']),$f->id);
            $f->configure(['include_name'=>'1','include_url'=>'1']); $f->accept(0,0); $f->accept(0,60);
            $slot=$f->slots()[0]; Assert::false(str_contains($slot['payload'],'private-name'));
            $private=json_decode($f->vault->decrypt($slot['private_cipher']),true);
            Assert::true($private['display_name']==='<private-name>'); Assert::true($private['site_origin']==='https://example.com');
            Assert::false(str_contains(json_encode($slot),'credential=excluded'));
        } finally { $f->close(); }
    }
}
