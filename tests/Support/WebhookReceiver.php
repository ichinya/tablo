<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

/** @phpstan-import-type Webhook from FixtureWireRows */
final class WebhookReceiver
{
    public readonly int $port;
    public readonly string $certificate;
    /** @var resource|false|null */
    private mixed $process=null;
    /** @var array<int, resource> */
    private array $pipes=[];
    public function __construct(TemporaryDirectory $directory,string $mode,#[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] string $bearer='',int $count=1)
    {
        [$this->certificate, $serverPem] = WebhookCertificate::create($directory);
        $errno = 0; $error = '';
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $this->port=(int)substr(\Tablo\Tests\Support\FixtureValue::string(strrchr(\Tablo\Tests\Support\FixtureValue::string(stream_socket_get_name(\Tablo\Tests\Support\FixtureNative::resource($socket),remote: false)),needle: ':')),offset: 1); fclose(\Tablo\Tests\Support\FixtureNative::resource($socket));
        $this->process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',dirname(__DIR__).'/fixtures/webhook-tls.php',(string)$this->port,$serverPem,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,cwd: null,env_vars: PHP_OS_FAMILY==='Windows'?['SystemRoot'=>FixtureValue::string(getenv('SystemRoot')),'WINDIR'=>FixtureValue::string(getenv('WINDIR'))]:[]);
        if (!is_resource($this->process)) { throw new \RuntimeException('Cannot start owned TLS receiver'); }
        fwrite(\Tablo\Tests\Support\FixtureValue::at($this->pipes, 0),json_encode(['payload'=>$payload,'bearer'=>$bearer,'count'=>$count],JSON_THROW_ON_ERROR)); fclose(\Tablo\Tests\Support\FixtureValue::at($this->pipes, 0));
        $deadline=\Tablo\MonotonicTime::integer(hrtime(true))+3_000_000_000;
        do{$peer=\Tablo\NativeWarning::ignore(function () use (&$errno, &$error) { return fsockopen('127.0.0.1',$this->port,$errno,$error,timeout: .1); });if($peer!==false){fclose($peer);return;}usleep(20_000);}while(\Tablo\MonotonicTime::integer(hrtime(true))<$deadline);
        $status=proc_get_status(\Tablo\Tests\Support\FixtureNative::resource($this->process));
        $diagnostic=$status['running']?'':stream_get_contents(\Tablo\Tests\Support\FixtureValue::at($this->pipes, 2),length: 2048);
        $kind = [];
        preg_match('/(?:Fatal error: Uncaught )?(JsonException|ValueError|TypeError|Error)/',\Tablo\Tests\Support\FixtureValue::string($diagnostic),$kind);
        $operation = [];
        preg_match('/(stream_socket_server|json_decode|stream_get_contents|fwrite|fgets)\(/',\Tablo\Tests\Support\FixtureValue::string($diagnostic),$operation);
        $this->close();
        throw new \RuntimeException('Owned TLS receiver did not start; observed exit '.$status['exitcode'].'; '.($kind[1]??'no-class').' '.($operation[1]??'no-operation').'.');
    }
    /** @return Webhook */
    public function result(): array
    {
        $deadline=\Tablo\MonotonicTime::integer(hrtime(true))+16_000_000_000;
        do{$status=proc_get_status(\Tablo\Tests\Support\FixtureNative::resource($this->process));if(!$status['running']){break;}usleep(10_000);}while(\Tablo\MonotonicTime::integer(hrtime(true))<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver did not finish.');}
        return FixtureWireRows::webhook(\Tablo\RequiredFile::bytes(stream_get_contents(FixturePipes::pipe($this->pipes, 1), length: 1024)));
    }
    public function close(): void
    {
        if(!is_resource($this->process)){return;}
        if(proc_get_status($this->process)['running']){proc_terminate($this->process);}
        $deadline=\Tablo\MonotonicTime::integer(hrtime(true))+1_000_000_000;
        do{$status=proc_get_status($this->process);if(!$status['running']){break;}usleep(10_000);}while(\Tablo\MonotonicTime::integer(hrtime(true))<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver stop unverified.');}
        \Tablo\ResourceStreams::close($this->pipes); proc_close($this->process);$this->process=null;
    }
}
