<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Row from NotificationMaintenanceRow
 * @phpstan-import-type Record from SqlNotificationSettingsActivationIdBearerCipherBlockedRow as Settings
 */
final class NotificationMaintenanceStatus
{
    /**
     * @param Row $slot
     * @param Settings $settings
     */
    public static function classify(array $slot, #[\SensitiveParameter] array $settings, int $now): ?string
    {
            if (!$slot['site_enabled'] || $slot['current_revision']!==$slot['config_revision']
                || $settings['revision']!==$slot['channel_revision'] || !self::enabled($settings, $slot['event'])) { return 'cancelled'; }
            if($now>=$slot['expires_at']){return 'expired';}
            if($slot['attempts']>=3){return 'failed';}
        return null;
    }

    /** @param Settings $settings */
    private static function enabled(array $settings, string $event): int
    {
        return match ($event) {
            'unavailable' => $settings['unavailable'],
            'recovery' => $settings['recovery'],
            'version_lag' => $settings['version_lag'],
            default => throw new \RuntimeException('Invalid notification event.'),
        };
    }
}
