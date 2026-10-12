<?php
declare(strict_types=1);

namespace Tablo;

final class OutputTimestamp
{
    public static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $value : null;
    }
    public static function freshness(?string $timestamp, int $now): string
    {
        if ($timestamp === null) { return 'unavailable'; }
        return $now - strtotime($timestamp) > 900 ? 'stale' : 'fresh';
    }
    public static function previous(mixed $raw, ?string $timestamp, int $now): string
    {
        if ($raw === null) { return 'never'; }
        if ($timestamp === null) { return 'unknown'; }
        return self::freshness($timestamp, $now);
    }

}
