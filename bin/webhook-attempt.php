<?php
declare(strict_types=1);

// Minimal one-attempt process: no autoload/bootstrap/env/session/PDO and no children.
ini_set('display_errors','0'); ini_set('log_errors','0');
set_error_handler(static function (): never { throw new RuntimeException('Webhook attempt failed.'); });
$result=['code'=>'invalid-data'];
try {
    foreach(['validation-exception','webhook-failure','webhook-address','http-failure','http-client'] as $file) {
        require dirname(__DIR__).'/app/'.$file.'.php';
    }
    $frame=stream_get_contents(STDIN,2049);
    if (!is_string($frame) || strlen($frame)<12 || strlen($frame)>2048) { throw new RuntimeException('Invalid frame.'); }
    $length=unpack('Nendpoint/Nbearer/Npayload',substr($frame,0,12));
    if ($length['endpoint']>500 || $length['bearer']>512 || $length['payload']>1024
        || array_sum($length)+12!==strlen($frame)) { throw new RuntimeException('Invalid frame.'); }
    $endpoint=substr($frame,12,$length['endpoint']);
    $bearer=substr($frame,12+$length['endpoint'],$length['bearer']);
    $payload=substr($frame,12+$length['endpoint']+$length['bearer']); unset($frame);
    $object=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
    if (!is_string($object['event_id']??null)) { throw new RuntimeException('Invalid frame.'); }
    $status=(new Tablo\HttpClient())->postBefore($endpoint,$payload,$object['event_id'],$bearer,hrtime(true)+6000000000);
    $result=['code'=>$status>=200&&$status<300?'sent':($status>=300&&$status<400?'redirect':'http'),'http_status'=>$status];
} catch (Tablo\WebhookFailure $error) { $result=['code'=>$error->reason]; }
catch (Throwable) { /* Fixed bounded result only; discard native args/message/previous. */ }
fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR)."\n");
