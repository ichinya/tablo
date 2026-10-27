<?php
declare(strict_types=1);

namespace Tablo;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** @phpstan-type Period array{value:string,label:string,from:string,to:string,seconds:int,bucket_seconds:int,options:list<array{value:string,label:string,selected:bool}>} */
final class AvailabilityPeriod
{
    private const OPTIONS = ['24h' => ['24 часа', 86_400], '7d' => ['7 дней', 604_800], '30d' => ['30 дней', 2_592_000]];

    /** @return Period */
    public static function select(mixed $value = '24h', ?DateTimeImmutable $now = null): array
    {
        if (!is_string($value) || !array_key_exists($value, self::OPTIONS)) {
            throw new InvalidArgumentException('Invalid availability period.');
        }
        [$label, $seconds] = self::OPTIONS[$value];
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $to = HistoryTime::canonical($now->format('Y-m-d\TH:i:s\Z'));
        $from = HistoryTime::canonical($now->sub(new DateInterval('PT' . $seconds . 'S'))->format('Y-m-d\TH:i:s\Z'));
        $options = [];
        foreach (self::OPTIONS as $key => [$optionLabel]) {
            $options[] = ['value' => $key, 'label' => $optionLabel, 'selected' => $key === $value];
        }
        return ['value' => $value, 'label' => $label, 'from' => $from, 'to' => $to,
            'seconds' => $seconds, 'bucket_seconds' => intdiv($seconds, num2: 120), 'options' => $options];
    }
}
