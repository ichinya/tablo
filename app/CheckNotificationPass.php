<?php
declare(strict_types=1);

namespace Tablo;

final class CheckNotificationPass
{
    public static function run(?NotificationDelivery $notifications,CheckFormat $format): void
    {
        $json=$format===CheckFormat::Json;
            if ($notifications !== null) {
                $delivery = $notifications->runOne();
                if (!$json && !in_array($delivery['code'], ['off', 'idle'], strict: true)) {
                    echo 'notification: ' . $delivery['code'] . PHP_EOL;
                }
            }
    }
}
