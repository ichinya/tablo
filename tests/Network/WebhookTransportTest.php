<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\WebhookAddress;
use Tablo\WebhookFailure;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\WebhookReceiver;
use Testo\Assert;
use Testo\Test;

final class WebhookTransportTest
{
    #[Test]
    public function globallyRoutableAllAddressAndRealSensitiveNativeFailureBoundaries(): void
    {
        foreach(['127.0.0.1','10.0.0.1','100.64.0.1','192.0.0.1','192.0.2.1','198.51.100.1','203.0.113.1',
            '224.0.0.1','240.0.0.1','::1','::ffff:8.8.8.8','fc00::1','fe80::1','ff02::1','2001:db8::1','2002::1','3fff::1'] as $address){
            Assert::false(WebhookAddress::globallyRoutable($address));
        }
        foreach(['8.8.8.8','1.1.1.1','2001:4860:4860::8888','2606:4700:4700::1111'] as $address){Assert::true(WebhookAddress::globallyRoutable($address));}
        $client=new class extends HttpClient{protected function resolve(string $host):array{return ['8.8.8.8','127.0.0.1'];}};
        $marker=bin2hex(random_bytes(20)); $error=null;
        try{$client->postBefore('https://receiver.example/'.$marker,'{}','fixture:1',$marker,hrtime(true)+1000000000);}catch(WebhookFailure $caught){$error=$caught;}
        Assert::same($error->reason,'ssrf'); Assert::same($error->getPrevious(),null);
        $client=new class extends HttpClient{protected function resolve(string $host):array{usleep(80000);return ['8.8.8.8'];}};
        $started=hrtime(true);
        try{$client->postBefore('https://receiver.example/'.$marker,'{}','fixture:1',$marker,hrtime(true)+10000000);}catch(WebhookFailure $caught){$error=$caught;}
        Assert::same($error->reason,'timeout'); Assert::true(hrtime(true)-$started>=80000000,'synchronous DNS exceeds curl deadline; parent supervision is required');
        // Inspect complete actual exception traces outside Testo's unbounded runner object graph.
        $directory=new TemporaryDirectory('tablo-webhook-trace-');
        try {
            $result=Subprocess::run([PHP_BINARY,dirname(__DIR__).'/fixtures/webhook-trace.php'],$directory,[]);
            Assert::same($result['exit_code'],0); Assert::same($result['stderr'],'');
            Assert::same($result['stdout'],"webhook complete trace checks=5\n");
        } finally { $directory->close(); }
    }

    #[Test]
    public function realTlsPostExactBodyAuthAbsenceRedirectCapsAndLostAckDuplicateKey(): void
    {
        foreach(['success','redirect','size','headers','lost','retry'] as $mode){
            $directory=new TemporaryDirectory('tablo-webhook-tls-'); $receiver=null;
            try {
                $bearer=$mode==='success'?'':bin2hex(random_bytes(24)); $payload=json_encode(['event_id'=>'fixture:unavailable:1','event'=>'unavailable']);
                $receiver=new WebhookReceiver($directory,$mode,$payload,$bearer,$mode==='lost'?2:1);
                $client=new class($receiver->certificate) extends HttpClient{
                    public function __construct(private readonly string $ca) {parent::__construct();}
                    protected function webhookAddresses(string $host):array{return ['127.0.0.1'];}
                    protected function configureWebhook(#[\SensitiveParameter] \CurlHandle $curl):void{curl_setopt($curl,CURLOPT_CAINFO,$this->ca);}
                };
                $url='https://receiver.example:'.$receiver->port.'/hook'; $failure=null; $status=null;
                for($i=0;$i<($mode==='lost'?2:1);$i++){
                    try{$status=$client->postBefore($url,$payload,'fixture:unavailable:1',$bearer,hrtime(true)+6000000000);}
                    catch(WebhookFailure $error){$failure=$error->reason;Assert::same($error->getPrevious(),null);unset($error);}
                }
                $result=$receiver->result(); Assert::true($result['exact']); Assert::true($result['authorization']); Assert::true($result['same_key']);
                Assert::same($result['count'],$mode==='lost'?2:1); Assert::same($result['redirect_targets'],0);
                if(in_array($mode,['size','headers'],true)){Assert::same($failure,'size');}
                elseif($mode==='lost'){Assert::same($failure,'invalid-response');}
                else{Assert::same($status,$mode==='redirect'?302:($mode==='retry'?503:204));}
            } finally {$receiver?->close();$receiver=$client=null;$directory->close();}
        }
    }
}
