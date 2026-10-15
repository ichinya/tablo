<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationMaintenanceStatus
{
    public static function classify(array $slot, #[\SensitiveParameter] array $settings, int $now): ?string
    {
            if (!$slot['site_enabled'] || $slot['current_revision']!==$slot['config_revision']
                || $settings['revision']!==$slot['channel_revision'] || !$settings[$slot['event']]) { return 'cancelled'; }
            if($now>=$slot['expires_at']){return 'expired';}
            if($slot['attempts']>=3){return 'failed';}
        return null;
    }
}
