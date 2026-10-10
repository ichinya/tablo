<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
ini_set('zend.exception_ignore_args','0');
$marker=bin2hex(random_bytes(24));
$clients=[
    new class extends \Tablo\HttpClient { protected function resolve(string $host): array { return ['8.8.8.8','127.0.0.1']; } },
    new class extends \Tablo\HttpClient { protected function resolve(string $host): array { return []; } },
    new class extends \Tablo\HttpClient {
        protected function resolve(string $host): array { return ['8.8.8.8']; }
        protected function configureWebhook(#[\SensitiveParameter] \CurlHandle $curl): void { curl_setopt_array($curl,['invalid-native-option'=>[]]); }
    },
];
$checks=0;
foreach($clients as $client){
    $caught=null;
    try { $client->postBefore('https://receiver.example/'.$marker,'{"private":"'.$marker.'"}','fixture:1',$marker,hrtime(true)+1000000000); }
    catch(\Tablo\WebhookFailure $error){ $caught=$error; }
    if($caught===null || $caught->getPrevious()!==null){throw new RuntimeException('Expected detached actual webhook failure.');}
    $inspection=\Tablo\Tests\Support\TraceInspector::inspect($caught,[$marker]);
    if($inspection['leaked'] || !$inspection['complete'] || $inspection['redacted']<3){throw new RuntimeException('Actual webhook complete trace refusal.');}
    ++$checks;
}
function deliberatelyLeakingWebhookProbe(string $marker): void
{
    (new \Tablo\HttpClient())->postBefore('https://127.0.0.1/'.$marker,'{}','fixture:1',$marker,hrtime(true)+1000000000);
}
try { deliberatelyLeakingWebhookProbe($marker); }
catch(\Tablo\WebhookFailure $error) {
    $inspection=\Tablo\Tests\Support\TraceInspector::inspect($error,[$marker]);
    if(!$inspection['leaked']){throw new RuntimeException('Leaking actual caller control missed.');} ++$checks;
}
function deliberatelyOpaqueWebhookProbe(mixed $opaque): void
{
    (new \Tablo\HttpClient())->postBefore('https://127.0.0.1/hook','{}','fixture:1','',hrtime(true)+1000000000);
}
try { deliberatelyOpaqueWebhookProbe(new PDO('sqlite::memory:')); }
catch(\Tablo\WebhookFailure $error) {
    $inspection=\Tablo\Tests\Support\TraceInspector::inspect($error,[$marker]);
    if($inspection['complete'] || $inspection['opaque']===[]){throw new RuntimeException('Opaque actual caller control missed.');} ++$checks;
}
echo 'webhook complete trace checks='.$checks."\n";
