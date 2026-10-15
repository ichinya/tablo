<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookChildResultShape
{
    public static function validate(mixed $result): void
    {
            $allowed=['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size','invalid-url','invalid-data','invalid-response'];
            if (!is_array($result) || array_diff(array_keys($result),['code','http_status'])!==[]
                || !in_array($result['code']??null,$allowed,strict: true)
                || ((($result['http_status'] ?? null) !== null) && (!is_int($result['http_status']) || $result['http_status']<100 || $result['http_status']>599))) { throw new WebhookFailure('child-frame'); }
            if ($result['code']==='sent' && (!(($result['http_status'] ?? null) !== null) || $result['http_status']<200 || $result['http_status']>=300)) { throw new WebhookFailure('child-frame'); }
    }
}
