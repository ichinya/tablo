<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookFailureResult
{
    /** @return (array{'code': string, 'stopped': false})|(array{'code': string, 'stopped': true}) */
    public static function finish(WebhookProcess $child,string $code): array
    {
        if (is_resource($child->process)) {
            $child->status=OwnedWebhookStop::stopOwned($child->process);
            if ($child->status['running']) { WebhookCustody::detach($child); return ['code'=>'stop-unverified','stopped'=>false]; }
        }
        return ['code'=>$code,'stopped'=>true];
    }
}
