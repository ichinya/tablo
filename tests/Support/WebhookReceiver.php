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
        $config=$directory->path.'/openssl.cnf';
        file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:receiver.example\nbasicConstraints=critical,CA:TRUE\n");
        $options=['config'=>$config,'digest_alg'=>'sha256','private_key_bits'=>2048,'x509_extensions'=>'ext'];
        $key=openssl_pkey_new($options); $csr=openssl_csr_new(['commonName'=>'receiver.example'],$key,$options);
        $cert=openssl_csr_sign($csr,null,$key,1,$options); openssl_x509_export($cert,$certPem); openssl_pkey_export($key,$keyPem,null,$options);
        $this->certificate=$directory->path.'/certificate.pem'; file_put_contents($this->certificate,$certPem);
        $serverPem=$directory->path.'/receiver.pem'; file_put_contents($serverPem,$certPem.$keyPem);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $this->port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
        $this->process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',dirname(__DIR__).'/fixtures/webhook-tls.php',(string)$this->port,$serverPem,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,null,PHP_OS_FAMILY==='Windows'?['SystemRoot'=>getenv('SystemRoot'),'WINDIR'=>getenv('WINDIR')]:[]);
        fwrite($this->pipes[0],json_encode(['payload'=>$payload,'bearer'=>$bearer,'count'=>$count],JSON_THROW_ON_ERROR)); fclose($this->pipes[0]);
        $deadline=hrtime(true)+3000000000;
        do{$peer=@fsockopen('127.0.0.1',$this->port,$errno,$error,.1);if($peer!==false){fclose($peer);return;}usleep(20000);}while(hrtime(true)<$deadline);
        $status=proc_get_status($this->process);
        $diagnostic=$status['running']?'':stream_get_contents($this->pipes[2],2048);
        preg_match('/(?:Fatal error: Uncaught )?(JsonException|ValueError|TypeError|Error)/',$diagnostic,$kind);
        preg_match('/(stream_socket_server|json_decode|stream_get_contents|fwrite|fgets)\(/',$diagnostic,$operation);
        $this->close();
        throw new \RuntimeException('Owned TLS receiver did not start; observed exit '.$status['exitcode'].'; '.($kind[1]??'no-class').' '.($operation[1]??'no-operation').'.');
    }
    public function result(): array
    {
        $deadline=hrtime(true)+16000000000;
        do{$status=proc_get_status($this->process);if(!$status['running']){break;}usleep(10000);}while(hrtime(true)<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver did not finish.');}
        return json_decode(stream_get_contents($this->pipes[1],1024),true,8,JSON_THROW_ON_ERROR);
    }
    public function close(): void
    {
        if(!is_resource($this->process)){return;}
        if(proc_get_status($this->process)['running']){proc_terminate($this->process);}
        $deadline=hrtime(true)+1000000000;
        do{$status=proc_get_status($this->process);if(!$status['running']){break;}usleep(10000);}while(hrtime(true)<$deadline);
        if($status['running']){throw new \RuntimeException('Owned TLS receiver stop unverified.');}
        foreach($this->pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}} proc_close($this->process);$this->process=null;
    }
}
