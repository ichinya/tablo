<?php
declare(strict_types=1);
namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class NotificationSettingsTest
{
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
