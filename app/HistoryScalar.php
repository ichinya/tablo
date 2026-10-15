<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryScalar
{
    public static function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if ($value !== null && (!is_int($value) || $value < $minimum || $value > $maximum)) { InvalidHistorySnapshot::invalid(); }
        return $value;
    }
    public static function code(mixed $value, array $codes): ?string
    {
        if ($value !== null && (!is_string($value) || !in_array($value, $codes, strict: true))) { InvalidHistorySnapshot::invalid(); }
        return $value;
    }
}
