<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationRetryStatus
{
    public static function status(array $claimed,array $result,int $now): string
    {
        if ($result['code'] === 'sent') { return 'sent'; }
        if ($now >= $claimed['slot']['expires_at']) { return 'expired'; }
        $retry=in_array($result['code'],['timeout','dns','refused','network','ambiguous','child-start','child-frame'],strict: true)
            || ($result['http_status'] ?? 0)===429 || ($result['http_status'] ?? 0)>=500;
        return $retry && $claimed['attempt']<3 ? 'pending' : 'failed';
    }
}
