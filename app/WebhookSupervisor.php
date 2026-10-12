<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Attempt array{code: string, stopped: bool, http_status?: ?int, elapsed_ms?: int, child_exit?: int, signaled?: bool, termsig?: int} */
final class WebhookSupervisor
{
    public function __construct(private readonly ?string $script=null,private readonly int $budgetMs=10_000)
    {
        if ($budgetMs<1 || $budgetMs>10_000) { throw new \InvalidArgumentException('Invalid notification deadline.'); }
    }
    /**
     * @param (\Closure(): bool)|null $stop
     * @return Attempt
     */
    public function attempt(#[\SensitiveParameter] string $endpoint,#[\SensitiveParameter] string $bearer,#[\SensitiveParameter] string $payload,?\Closure $stop=null): array
    {
        if (WebhookCustody::blocked()) { return ['code'=>'stop-unverified','stopped'=>false]; }
        $frame=WebhookFrame::encode($endpoint,$bearer,$payload);
        if ($frame===null) { return ['code'=>'size','stopped'=>true]; }
        $child=new WebhookProcess(); $code='child-start';
        $started=MonotonicTime::nanoseconds(); $deadline=$started+$this->budgetMs*1_000_000;
        set_error_handler(static function (): never { throw new WebhookFailure('child-start'); });
        try {
            WebhookChildLaunch::start($child,$this->script);
            $code='child-frame';
            WebhookChildInput::send($child,$frame); unset($frame);
            $code=WebhookChildWait::observe($child,$stop,$deadline);
            if ($child->observed()['running']) { return WebhookStoppedResult::finish($child,$code,$started); }
            return WebhookChildResult::read($child)+['stopped'=>true,'elapsed_ms'=>WebhookElapsed::milliseconds($started),'child_exit'=>$child->observed()['exitcode']];
        } catch (\Throwable) {
            return WebhookFailureResult::finish($child,$code);
        } finally {
            WebhookCustody::finish($child);
            restore_error_handler();
        }
    }
}
