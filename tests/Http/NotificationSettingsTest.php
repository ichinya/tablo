<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\NotificationOutbox;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Testo\Assert;
use Testo\Test;

final class NotificationSettingsTest
{
    #[Test]
    public function realBlankKeepAndMetadataPostsPreserveLaterRecovery(): void
    {
        $web=new WebFixture(); $db=$sites=$vault=$outbox=null;
        try {
            $csrf=$web->authenticate(); $db=$web->database(); $vault=TokenVault::forDatabase($db);
            $wall=1000;
            $sites=new SiteRepository($db,$vault,function()use(&$wall):int{return $wall;});
            $id=$sites->save(IncidentFixtures::site());
            $fields=['_csrf'=>$csrf,'revision'=>'0','enabled'=>'1','unavailable'=>'1','recovery'=>'1','endpoint'=>'https://receiver.example/hook'];
            Assert::same($web->request('/settings/notifications',$fields)['status'],303);
            foreach ([false,true] as $metadata) {
                $offset=$metadata?300:0;
                $wall=1000+$offset; Assert::true(IncidentFixtures::accept($sites,$id,0,gmdate('Y-m-d\TH:i:s\Z',1767225600+$offset)));
                $wall+=60; Assert::true(IncidentFixtures::accept($sites,$id,0,gmdate('Y-m-d\TH:i:s\Z',1767225660+$offset)));
                $outbox=new NotificationOutbox($db); $claim=$outbox->claim($wall); Assert::true($outbox->acknowledge($claim,['code'=>'sent','http_status'=>204],$wall));
                $old=$db->query('SELECT * FROM notification_settings')->fetch();
                $save=['_csrf'=>$csrf,'revision'=>(string)$old['revision'],'enabled'=>'1','unavailable'=>'1','recovery'=>'1','endpoint'=>'','bearer'=>''];
                if ($metadata) { $save['include_name']='1'; }
                Assert::same($web->request('/settings/notifications',$save)['status'],303);
                $new=$db->query('SELECT * FROM notification_settings')->fetch();
                Assert::same($new['activation_id'],$old['activation_id']); Assert::same($new['endpoint_cipher'],$old['endpoint_cipher']);
                Assert::same($new['revision'],$old['revision']+($metadata?1:0));
                $wall+=60; Assert::true(IncidentFixtures::accept($sites,$id,1,gmdate('Y-m-d\TH:i:s\Z',1767225720+$offset)));
                $recovery=$db->query("SELECT * FROM notification_slots WHERE event='recovery'")->fetch();
                Assert::true(is_array($recovery)); Assert::same($recovery['source_id'],$claim['slot']['source_id']);
                if ($metadata) { Assert::same(json_decode($vault->decrypt($recovery['private_cipher']),true),['display_name'=>'Service']); }
                $recoveryClaim=$outbox->claim($wall); Assert::true($outbox->acknowledge($recoveryClaim,['code'=>'sent','http_status'=>204],$wall));
            }
        } finally { $outbox=$sites=$vault=$db=null; $web->close(); }
    }

    #[Test]
    public function authenticatedSiteFilterReachesNewFailureAfterFiftyTerminalSlotsReadOnly(): void
    {
        $web=new WebFixture(); $db=$sites=null;
        try {
            $web->authenticate(); $db=$web->database(); $vault=TokenVault::forDatabase($db); $sites=new SiteRepository($db,$vault);
            $cipher=$vault->encrypt('{"display_name":"hidden-cipher"}');
            $insert=$db->prepare("INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,
                private_cipher,created_at,expires_at,due_at,status,attempts,last_code) VALUES (?,'unavailable',?,1,0,0,?,?,1000,4600,1000,?,1,?)");
            for ($n=0;$n<56;$n++) {
                $id=$sites->save(IncidentFixtures::site());
                $insert->execute([$id,'synthetic:'.$id,'{"private":"hidden-payload"}',$cipher,$n===55?'pending':'sent',$n===55?'timeout':'sent']);
            }
            $insert=null;
            $before=$db->query('SELECT * FROM notification_slots ORDER BY site_id,event')->fetchAll();
            $settings=$db->query('SELECT * FROM notification_settings')->fetchAll();
            Assert::same($web->request('/settings?notification_site_id='.$id,null,false)['status'],303);
            $page=$web->request('/settings'); Assert::same($page['status'],200);
            Assert::same(substr_count($page['body'],' · попытки '),50);
            Assert::true(str_contains($page['body'],'Показаны первые 50'));
            Assert::false(str_contains($page['body'],'Сайт '.$id.' · unavailable'));
            $page=$web->request('/settings?notification_site_id='.$id); Assert::same($page['status'],200);
            Assert::same(substr_count($page['body'],' · попытки '),1);
            Assert::true(str_contains($page['body'],'Сайт '.$id.' · unavailable · pending'));
            Assert::true(str_contains($page['body'],'timeout')); Assert::false(str_contains($page['body'],'hidden-payload')); Assert::false(str_contains($page['body'],'hidden-cipher'));
            $db->exec("UPDATE notification_slots SET last_code='<script>synthetic</script>' WHERE site_id=".$id);
            $escaped=$web->request('/settings?notification_site_id='.$id);
            Assert::false(str_contains($escaped['body'],'<script>synthetic</script>')); Assert::true(str_contains($escaped['body'],'&lt;script&gt;synthetic&lt;/script&gt;'));
            $db->exec("UPDATE notification_slots SET last_code='timeout' WHERE site_id=".$id);
            foreach (['0','-1','abc','9223372036854775808','%3Cscript%3E','1&notification_site_id[]=2'] as $bad) {
                $invalid=$web->request('/settings?notification_site_id='.$bad); Assert::same($invalid['status'],400); Assert::false(str_contains($invalid['body'],'<script>'));
            }
            Assert::same($web->request('/settings?notification_site_id=999999')['status'],200);
            Assert::same($db->query('SELECT * FROM notification_slots ORDER BY site_id,event')->fetchAll(),$before);
            Assert::same($db->query('SELECT * FROM notification_settings')->fetchAll(),$settings);
        } finally { $insert=$sites=$vault=$db=null; $web->close(); }
    }

    #[Test]
    public function actualRenderedAuthCsrfEncryptedSaveBlankKeepRemoveAndSafe422(): void
    {
        $web=new WebFixture(); $db=null;
        try {
            $csrf=$web->authenticate();
            Assert::same($web->request('/settings/notifications',['revision'=>'0'],false)['status'],303);
            Assert::same($web->request('/settings/notifications',['_csrf'=>'wrong','revision'=>'0'])['status'],419);
            $page=$web->request('/settings'); Assert::same($page['status'],200);
            Assert::true(str_contains($page['body'],'name="endpoint"')); Assert::true(str_contains($page['body'],'value="" autocomplete="new-password"'));
            $secret=bin2hex(random_bytes(24)); $endpoint='https://receiver.example/'.bin2hex(random_bytes(16));
            $fields=['_csrf'=>$csrf,'revision'=>'0','enabled'=>'1','endpoint'=>$endpoint,'bearer'=>$secret,'recovery'=>'1'];
            Assert::same($web->request('/settings/notifications',$fields)['status'],303);
            $db=$web->database(); $old=$db->query('SELECT * FROM notification_settings')->fetch();
            Assert::true($old['endpoint_cipher']!==$endpoint && $old['bearer_cipher']!==$secret);
            $page=$web->request('/settings'); Assert::false(str_contains($page['body'],$secret)); Assert::false(str_contains($page['body'],$endpoint));
            foreach([['revision'=>'0'],['revision'=>['array']],['endpoint'=>['array']],['endpoint'=>'https://receiver.example/<script>'],['bearer'=>str_repeat('x',513)]] as $bad){
                $response=$web->request('/settings/notifications',array_replace($fields,['revision'=>'1'],$bad));
                Assert::same($response['status'],422); Assert::false(str_contains($response['body'],$secret)); Assert::false(str_contains($response['body'],$endpoint));
            }
            $revision=(string)$db->query('SELECT revision FROM notification_settings')->fetchColumn();
            Assert::same($web->request('/settings/notifications',['_csrf'=>$csrf,'revision'=>$revision,'enabled'=>'1','recovery'=>'1'])['status'],303);
            $kept=$db->query('SELECT * FROM notification_settings')->fetch();
            Assert::true($kept['bearer_cipher']===$old['bearer_cipher']);
            Assert::same($web->request('/settings/notifications',['_csrf'=>$csrf,'revision'=>(string)$kept['revision'],'remove_endpoint'=>'1','remove_bearer'=>'1'])['status'],303);
            $cleared=$db->query('SELECT endpoint_cipher,bearer_cipher,enabled FROM notification_settings')->fetch();
            Assert::same($cleared,['endpoint_cipher'=>null,'bearer_cipher'=>null,'enabled'=>0]);
            Assert::true(str_contains($page['headers'],'Cache-Control: no-store'));
        } finally { $db=null; $web->close(); }
    }
}
