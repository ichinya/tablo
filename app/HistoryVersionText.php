<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryVersionText
{
    public static function version(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || strlen($value) < 1 || strlen($value) > 200
            || preg_match('//u', $value) !== 1 || str_contains($value, "\0"))) { InvalidHistorySnapshot::invalid(); }
        return $value;
    }
    public static function commit(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || !preg_match('/^[a-f0-9]{7,64}$/iD', $value))) { InvalidHistorySnapshot::invalid(); }
        return $value === null ? null : strtolower($value);
    }
}
