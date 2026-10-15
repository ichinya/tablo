<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationResult
{
    public static function validate(array $result): void
    {
        if (!in_array($result['code']??null,['sent','http','redirect','timeout','dns','ssrf','tls','refused','network','size',
            'invalid-url','invalid-data','invalid-response','ambiguous','child-start','child-frame'],strict: true)
            || ((($result['http_status'] ?? null) !== null) && (!is_int($result['http_status']) || $result['http_status']<100 || $result['http_status']>599))) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
        if ($result['code']==='sent' && (!(($result['http_status'] ?? null) !== null) || $result['http_status']<200 || $result['http_status']>=300)) {
            throw new \InvalidArgumentException('Invalid notification result.');
        }
    }

}
