<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Tablo\Tests\Support\IncidentFixtures;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Testo\Assert;
use Testo\Test;

final class NotificationSettingsTest
{
    #[Test]
    public function realBlankKeepAndMetadataPostsPreserveLaterRecovery(): void
    {
        $web=new WebFixture(); $outbox = null; $vault = null; $sites = null; $db = null;
        try {
            $csrf=$web->authenticate(); $db=$web->database(); $vault=TokenVault::forDatabase($db);
            $wall=1000;
            $sites=new SiteRepository($db,$vault,static function()use(&$wall):int{return $wall;});
            $id=$sites->save(IncidentFixtures::site());
            $fields=['_csrf'=>$csrf,'revision'=>'0','enabled'=>'1','unavailable'=>'1','recovery'=>'1','endpoint'=>'https://receiver.example/hook'];
            Assert::same($web->request('/settings/notifications',$fields)['status'],303);
            foreach ([false,true] as $metadata) {
                $offset=$metadata?300:0;
                [$outbox, $claim] = (new NotificationRecoveryFixture($sites, $db))->opening($id, $wall, $offset);
                $old=$db->query('SELECT * FROM notification_settings')->fetch();
                $save=['_csrf'=>$csrf,'revision'=>(string)$old['revision'],'enabled'=>'1','unavailable'=>'1','recovery'=>'1','endpoint'=>'','bearer'=>''];
                if ($metadata) { $save['include_name']='1'; }
                Assert::same($web->request('/settings/notifications',$save)['status'],303);
                (new NotificationRecoveryAssertions($db))->retained($old, $metadata ? 1 : 0);
                (new NotificationDeliveryAssertions($db, $sites, $vault, $outbox))->delivered([$offset, $id, $claim], $wall);
            }
        } finally { $db = null; $vault = null; $sites = null; $outbox = null; $web->close(); }
    }

    #[Test]
    public function authenticatedSiteFilterReachesNewFailureAfterFiftyTerminalSlotsReadOnly(): void
    {
        $web=new WebFixture(); $sites = null; $db = null;
        try {
            $web->authenticate(); $db=$web->database(); $vault=TokenVault::forDatabase($db); $sites=new SiteRepository($db,$vault);
            $id=NotificationSlotFixture::create($sites, $vault, $db);
            $insert=null;
            $before=$db->query('SELECT * FROM notification_slots ORDER BY site_id,event')->fetchAll();
            $settings=$db->query('SELECT * FROM notification_settings')->fetchAll();
            Assert::same($web->request(path: '/settings?notification_site_id='.$id, data: null, options: new \Tablo\Tests\Support\WebRequestOptions(cookies: (false) ? \Tablo\Tests\Support\CookiePolicy::Retained : \Tablo\Tests\Support\CookiePolicy::Omitted))['status'],303);
            $page=$web->request('/settings'); Assert::same($page['status'],200);
            Assert::same(substr_count($page['body'],needle: ' · попытки '),50);
            Assert::true(str_contains($page['body'],'Показаны первые 50'));
            Assert::false(str_contains($page['body'],'Сайт '.$id.' · unavailable'));
            $page=$web->request('/settings?notification_site_id='.$id); Assert::same($page['status'],200);
            Assert::same(substr_count($page['body'],needle: ' · попытки '),1);
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
        } finally { $db = null; $vault = null; $sites = null; $insert = null; $web->close(); }
    }

    #[Test]
    public function actualRenderedAuthCsrfEncryptedSaveBlankKeepRemoveAndSafe422(): void
    {
        $web=new WebFixture(); $db=null;
        try {
            $csrf=$web->authenticate();
            Assert::same($web->request(path: '/settings/notifications', data: ['revision'=>'0'], options: new \Tablo\Tests\Support\WebRequestOptions(cookies: (false) ? \Tablo\Tests\Support\CookiePolicy::Retained : \Tablo\Tests\Support\CookiePolicy::Omitted))['status'],303);
            Assert::same($web->request('/settings/notifications',['_csrf'=>'wrong','revision'=>'0'])['status'],419);
            $page=$web->request('/settings'); Assert::same($page['status'],200);
            Assert::true(str_contains($page['body'],'name="endpoint"')); Assert::true(str_contains($page['body'],'value="" autocomplete="new-password"'));
            $secret=bin2hex(random_bytes(24)); $endpoint='https://receiver.example/'.bin2hex(random_bytes(16));
            $fields=['_csrf'=>$csrf,'revision'=>'0','enabled'=>'1','endpoint'=>$endpoint,'bearer'=>$secret,'recovery'=>'1'];
            Assert::same($web->request('/settings/notifications',$fields)['status'],303);
            $db=$web->database(); $old=$db->query('SELECT * FROM notification_settings')->fetch();
            Assert::true($old['endpoint_cipher']!==$endpoint && !hash_equals($old['bearer_cipher'], $secret) );
            $page=$web->request('/settings'); Assert::false(str_contains($page['body'],$secret)); Assert::false(str_contains($page['body'],$endpoint));
            foreach([['revision'=>'0'],['revision'=>['array']],['endpoint'=>['array']],['endpoint'=>'https://receiver.example/<script>'],['bearer'=>str_repeat('x',times: 513)]] as $bad){
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
