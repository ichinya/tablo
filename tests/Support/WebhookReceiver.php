<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class WebhookReceiver
{
    public readonly int $port;
    public readonly string $certificate;
    private mixed $process=null;
    private array $pipes=[];
    public function __construct(TemporaryDirectory $directory,string $mode,#[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] string $bearer='',int $count=1)
    {
        [$this->certificate, $serverPem] = WebhookCertificate::create($directory);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $this->port=(int)substr(strrchr(stream_socket_get_name($socket,remote: false),needle: ':'),offset: 1); fclose($socket);
        $this->process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',dirname(__DIR__).'/fixtures/webhook-tls.php',(string)$this->port,$serverPem,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,cwd: null,env_vars: PHP_OS_FAMILY==='Windows'?['SystemRoot'=>getenv('SystemRoot'),'WINDIR'=>getenv('WINDIR')]:[]);
        fwrite($this->pipes[0],json_encode(['payload'=>$payload,'bearer'=>$bearer,'count'=>$count],JSON_THROW_ON_ERROR)); fclose($this->pipes[0]);
        $deadline=hrtime(true)+3_000_000_000;
        do{$peer=\Tablo\NativeWarning::ignore(function () use (&$errno, &$error) { return fsockopen('127.0.0.1',$this->port,$errno,$error,timeout: .1); });if($peer!==false){fclose($peer);return;}usleep(20_000);}while(hrtime(true)<$deadline);
        $status=proc_get_status($this->process);
        $diagnostic=$status['running']?'':stream_get_contents($this->pipes[2],length: 2048);
        preg_match('/(?:Fatal error: Uncaught )?(JsonException|ValueError|TypeError|Error)/',$diagnostic,$kind);
        preg_match('/(stream_socket_server|json_decode|stream_get_contents|fwrite|fgets)\(/',$diagnostic,$operation);
        $this->close();
        throw new \RuntimeException('Owned TLS receiver did not start; observed exit '.$status['exitcode'].'; '.($kind[1]??'no-class').' '.($operation[1]??'no-operation').'.');
    }
    public function result(): array
    {
        $deadline=hrtime(true)+16_000_000_000;
        do{$status=proc_get_status($this->process);if(!$status['running']){break;}usleep(10_000);}while(hrtime(true)<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver did not finish.');}
        return json_decode(stream_get_contents($this->pipes[1],length: 1024),associative: true,depth: 8,flags: JSON_THROW_ON_ERROR);
    }
    public function close(): void
    {
        if(!is_resource($this->process)){return;}
        if(proc_get_status($this->process)['running']){proc_terminate($this->process);}
        $deadline=hrtime(true)+1_000_000_000;
        do{$status=proc_get_status($this->process);if(!$status['running']){break;}usleep(10_000);}while(hrtime(true)<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver stop unverified.');}
        \Tablo\ResourceStreams::close($this->pipes); proc_close($this->process);$this->process=null;
    }
}
