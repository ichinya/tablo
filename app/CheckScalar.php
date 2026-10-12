<?php
declare(strict_types=1);

namespace Tablo;

final class CheckScalar
{
    public static function integer(mixed $value, int $min = 0, ?int $max = null): ?int
    {
        if ($value === null) { return null; }
        if (!is_int($value) || $value < $min || ($max !== null && $value > $max)) { self::invalid(); }
        return $value;
    }
    public static function choice(mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, strict: true)) { self::invalid(); }
        return $value;
    }
    public static function present(mixed $value): bool
    {
        if ($value !== null && !is_string($value)) { self::invalid(); }
        return $value !== null;
    }
    public static function invalid(): never { throw new \UnexpectedValueException('Invalid check output.'); }
}
