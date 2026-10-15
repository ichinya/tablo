<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','0');
set_error_handler(static fn():bool=>true);
$expected=json_decode(stream_get_contents(STDIN,4096),true,8,JSON_THROW_ON_ERROR);
$context=stream_context_create(['ssl'=>['local_cert'=>$argv[2],'verify_peer'=>false]]);
$server=stream_socket_server('tcp://127.0.0.1:'.$argv[1],$number,$message,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if($server===false){echo json_encode(['server_errno'=>$number]);exit(2);}
$deadline=hrtime(true)+15000000000; $count=0; $exact=true; $authorization=true; $keys=[];
while($count<($expected['count']??1)&&hrtime(true)<$deadline){
    $peer=stream_socket_accept($server,.2); if($peer===false){continue;}
    stream_set_timeout($peer,1);
    if(stream_socket_enable_crypto($peer,true,STREAM_CRYPTO_METHOD_TLS_SERVER)!==true){fclose($peer);continue;}
    $line=fgets($peer,2048); $headers=[];
    while(($header=fgets($peer,2048))!==false&&trim($header)!==''){
        $pair=explode(':',$header,2); if(count($pair)===2){$headers[strtolower($pair[0])]=trim($pair[1]);}
    }
    $length=(int)($headers['content-length']??0); $body='';
    while(strlen($body)<$length && $length<=8192){$part=fread($peer,$length-strlen($body));if($part===false||$part===''){break;}$body.=$part;}
    ++$count;
    $exact=$exact&&str_starts_with($line??'','POST /hook ')&&$body===$expected['payload'];
    $authorization=$authorization&&($headers['authorization']??'')===($expected['bearer']===''?'':'Bearer '.$expected['bearer']);
    $keys[]=$headers['idempotency-key']??'';
    $mode=$argv[3];
    if($mode==='lost'){fclose($peer);continue;}
    if($mode==='stall'){sleep(3);}
    $response=$mode==='size'?str_repeat('x',4097):'';
    $status=$mode==='retry'?503:($mode==='redirect'?302:($mode==='size'?200:204));
    $extra=$mode==='redirect'?"Location: https://127.0.0.1:1/forbidden\r\n":($mode==='headers'?"X-Large: ".str_repeat('x',17000)."\r\n":'');
    fwrite($peer,"HTTP/1.1 $status Result\r\nContent-Length: ".strlen($response)."\r\n$extra\r\n".$response);
    fclose($peer);
}
fclose($server);
echo json_encode(['count'=>$count,'exact'=>$exact,'authorization'=>$authorization,'same_key'=>count(array_unique($keys))===1,'redirect_targets'=>0]);
