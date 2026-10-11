<?php
declare(strict_types=1);

namespace Tablo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class HistoryRetention
{
    public static function days(mixed $value = false): int
    {
        if ($value === false) { return 30; }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,3}$/D', $value) || (int) $value > 3650) {
            throw new InvalidArgumentException('Invalid history retention.');
        }
        return (int) $value;
    }

    public static function cutoff(int $days, ?DateTimeImmutable $now = null): string
    {
        if ($days < 1 || $days > 3650) { throw new InvalidArgumentException('Invalid history retention.'); }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return HistoryTime::canonical($now->setTimezone(new DateTimeZone('UTC'))
            ->modify('-' . ($days * 86400) . ' seconds')->format('Y-m-d\TH:i:s\Z'));
    }
}
