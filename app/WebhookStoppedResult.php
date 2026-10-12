<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookStoppedResult
{
    public static function finish(WebhookProcess $child,string $code,int $started): array
    {
        $child->status=OwnedWebhookStop::stopOwned($child->process);
        if ($child->status['running']) {
            WebhookCustody::detach($child);
            return ['code'=>'stop-unverified','stopped'=>false,'elapsed_ms'=>WebhookElapsed::milliseconds($started)];
        }
        return ['code'=>$code,'stopped'=>true,'elapsed_ms'=>WebhookElapsed::milliseconds($started),
            'child_exit'=>$child->status['exitcode'],'signaled'=>$child->status['signaled'],'termsig'=>$child->status['termsig']];
    }
}
