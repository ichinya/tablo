<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/RuntimeIni.php';

// Minimal one-attempt process: no autoload/bootstrap/env/session/PDO and no children.
\Tablo\RuntimeIni::set('display_errors',value: '0'); \Tablo\RuntimeIni::set('log_errors',value: '0');
set_error_handler(static function (): never { throw new RuntimeException('Webhook attempt failed.'); });
$result=['code'=>'invalid-data'];
try {
    foreach(['ValidationException','WebhookFailure','WebhookHostSyntax','WebhookPublicAddress','WebhookAddress','HttpFailure','NativeWarning','PublicDns',
        'WebhookAddressAdmission','WebhookPayload','WebhookResponseBudget','CurlWebhookOptions','CurlFraming',
        'CurlWebhookTransport','HttpClient'] as $file) {
        require dirname(__DIR__).'/app/'.$file.'.php';
    }
    $frame=stream_get_contents(STDIN,length: 2049);
    if (!is_string($frame) || strlen($frame)<12 || strlen($frame)>2048) { throw new RuntimeException('Invalid frame.'); }
    $length=unpack('Nendpoint/Nbearer/Npayload',substr($frame,offset: 0,length: 12));
    if ($length['endpoint']>500 || $length['bearer']>512 || $length['payload']>1024
        || array_sum($length)+12!==strlen($frame)) { throw new RuntimeException('Invalid frame.'); }
    $endpoint=substr($frame,offset: 12,length: $length['endpoint']);
    $bearer=substr($frame,12+$length['endpoint'],$length['bearer']);
    $payload=substr($frame,12+$length['endpoint']+$length['bearer']); unset($frame);
    $object=json_decode($payload,associative: true,depth: 8,flags: JSON_THROW_ON_ERROR);
    if (!is_string($object['event_id']??null)) { throw new RuntimeException('Invalid frame.'); }
    $status=(new Tablo\HttpClient())->postBefore($endpoint,$payload,$object['event_id'],$bearer,hrtime(true)+6_000_000_000);
    $result=['code'=>$status>=200&&$status<300?'sent':(match (true) { ($status>=300&&$status<400) => 'redirect', default => 'http' }),'http_status'=>$status];
} catch (Tablo\WebhookFailure $error) { $result=['code'=>$error->reason]; }
catch (Throwable $discardedCleanup) { /* Fixed bounded result only; discard native args/message/previous. */  unset($discardedCleanup); }
fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR)."\n");
