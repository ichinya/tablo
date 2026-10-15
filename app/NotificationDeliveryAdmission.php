<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationDeliveryAdmission
{
    /** @param (\Closure(): bool)|null $stop */
    public static function refuse(\PDO $db, #[\SensitiveParameter] TokenVault $vault, ?\Closure $stop): ?string
    {
            if ($db->inTransaction()) { return 'storage-busy'; }
            $settings=(new NotificationSettings($db,$vault))->get();
            if ($settings['blocked']) { return 'stop-unverified'; }
            if (!$settings['enabled'] || !$settings['endpoint_present']
                || !($settings['unavailable']||$settings['recovery']||$settings['version_lag'])) { return 'off'; }
            if ($stop!==null && $stop()) { return 'stopped'; }
        return null;
    }
}
