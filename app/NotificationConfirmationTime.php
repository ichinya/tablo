<?php
declare(strict_types=1);

namespace Tablo;

final class NotificationConfirmationTime
{
    public static function seconds(string $sample): int
    {
        $seconds = strtotime($sample);
        if ($seconds === false) { throw new \RuntimeException('Invalid notification checkpoint time.'); }
        return $seconds;
    }
}
