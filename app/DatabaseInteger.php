<?php
declare(strict_types=1);

namespace Tablo;

final class DatabaseInteger
{
    /** JSON decoding observes the actual runtime integer width, including unsupported 32-bit builds. */
    public static function platform64(): bool { return is_int(json_decode('9223372036854775807', flags: JSON_THROW_ON_ERROR)); }
    public static function positive(mixed $value): int
    {
        $integer = self::checked($value);
        if ($integer <= 0) { self::refuse(); }
        return $integer;
    }
    public static function checked(mixed $value): int
    {
        if (is_int($value)) { return $value; }
        if (!self::platform64() || !is_string($value) || !preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value)) { self::refuse(); }
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, offset: 1) : $value;
        $maximum = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) { self::refuse(); }
        return (int) $value;
    }

    private static function refuse(): never { throw new DatabaseFailure('schema'); }
}
