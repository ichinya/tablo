<?php
declare(strict_types=1);
namespace Tablo;

final class WebhookSupervisor
{
    // An unobserved stop retains this process's custody and forbids another launch.
    private static array $unreleased = [];
    private static array $active = [];
    private static bool $shutdownRegistered = false;
    public function __construct(private readonly ?string $script = null, private readonly int $budgetMs = 10000)
    {
        if ($budgetMs < 1 || $budgetMs > 10000) { throw new \InvalidArgumentException('Invalid notification deadline.'); }
    }

    public function attempt(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] string $bearer,
        #[\SensitiveParameter] string $payload, ?\Closure $stop = null): array
    {
        if (self::$unreleased !== []) { return ['code'=>'stop-unverified','stopped'=>false]; }
        if (strlen($endpoint)>500 || strlen($bearer)>512 || strlen($payload)>1024) { return ['code'=>'size','stopped'=>true]; }
        $frame=pack('N3',strlen($endpoint),strlen($bearer),strlen($payload)).$endpoint.$bearer.$payload;
        $process=null; $pipes=[]; $status=null; $code='child-start';
        $started=hrtime(true); $deadline=$started+$this->budgetMs*1000000;
        set_error_handler(static function (): never { throw new WebhookFailure('child-start'); });
        try {
            $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','log_errors=0',
                $this->script??dirname(__DIR__).'/bin/webhook-attempt.php'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
                PHP_OS_FAMILY==='Windows'?['SystemRoot'=>getenv('SystemRoot'),'WINDIR'=>getenv('WINDIR')]:[],
                ['bypass_shell'=>true,'suppress_errors'=>true]);
            if (!is_resource($process)) { throw new WebhookFailure('child-start'); }
            self::$active = [$process,$pipes];
            if (!self::$shutdownRegistered) {
                register_shutdown_function(self::shutdown(...));
                self::$shutdownRegistered = true;
            }
            $code='child-frame';
            // One <=2048-byte frame fits observed supported OS anonymous-pipe buffers.
            // No polling reads of live Windows pipes; child writes <=1024 bytes total.
            if (fwrite($pipes[0],$frame)!==strlen($frame)) { throw new WebhookFailure('child-frame'); }
            fclose($pipes[0]); unset($pipes[0],$frame);
            do {
                $status=proc_get_status($process);
                if (!$status['running']) { break; }
                if (($stop !== null && $stop()) || hrtime(true)>=$deadline) { $code='timeout'; break; }
                usleep(10000);
            } while (true);
            if ($status['running']) {
                $status=self::stopOwned($process);
                if ($status['running']) {
                    self::$unreleased[]=[$process,$pipes]; $process=null; $pipes=[];
                    return ['code'=>'stop-unverified','stopped'=>false,'elapsed_ms'=>(int)((hrtime(true)-$started)/1000000)];
                }
                return ['code'=>$code,'stopped'=>true,'elapsed_ms'=>(int)((hrtime(true)-$started)/1000000),
                    'child_exit'=>$status['exitcode'],'signaled'=>$status['signaled'],'termsig'=>$status['termsig']];
            }
            $stdout=stream_get_contents($pipes[1],1025); $stderr=stream_get_contents($pipes[2],1025);
            if (!is_string($stdout) || strlen($stdout)>1024 || $stderr!=='' || $status['exitcode']!==0) { throw new WebhookFailure('child-frame'); }
            $result=json_decode($stdout,true,8,JSON_THROW_ON_ERROR);
            $allowed=['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size','invalid-url','invalid-data','invalid-response'];
            if (!is_array($result) || array_diff(array_keys($result),['code','http_status'])!==[]
                || !in_array($result['code']??null,$allowed,true)
                || (isset($result['http_status']) && (!is_int($result['http_status']) || $result['http_status']<100 || $result['http_status']>599))) { throw new WebhookFailure('child-frame'); }
            if ($result['code']==='sent' && (!isset($result['http_status']) || $result['http_status']<200 || $result['http_status']>=300)) { throw new WebhookFailure('child-frame'); }
            return $result+['stopped'=>true,'elapsed_ms'=>(int)((hrtime(true)-$started)/1000000),'child_exit'=>$status['exitcode']];
        } catch (\Throwable) {
            if (is_resource($process)) {
                $status=self::stopOwned($process);
                if ($status['running']) {
                    self::$unreleased[]=[$process,$pipes]; $process=null; $pipes=[];
                    return ['code'=>'stop-unverified','stopped'=>false];
                }
            }
            return ['code'=>$code,'stopped'=>true];
        } finally {
            // Exceptional partial startup also must positively stop before reaping.
            if (is_resource($process)) {
                $status=self::stopOwned($process);
                if ($status['running']) { self::$unreleased[]=[$process,$pipes]; }
                else { foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}} proc_close($process); }
            }
            self::$active = [];
            restore_error_handler();
        }
    }

    private static function stopOwned(#[\SensitiveParameter] mixed $process): array
    {
        try {
            $status=proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $grace=hrtime(true)+1000000000;
                while ($status['running'] && hrtime(true)<$grace) {
                    usleep(10000); $status=proc_get_status($process);
                }
            }
            return $status;
        } catch (\Throwable) {
            // Failed observation is uncertainty, never evidence that custody ended.
            return ['running'=>true,'exitcode'=>-1,'signaled'=>false,'termsig'=>0];
        }
    }

    private static function shutdown(): void
    {
        // Only our single child; no process-name or process-tree cleanup.
        foreach (self::$active === [] ? self::$unreleased : [self::$active] as [$process,$pipes]) {
            if (!is_resource($process)) { continue; }
            try {
                $status=self::stopOwned($process);
                if (!$status['running']) {
                    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                    proc_close($process);
                }
            } catch (\Throwable) { /* No secret-bearing output during shutdown. */ }
        }
    }
}
