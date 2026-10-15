<?php
declare(strict_types=1);

namespace Tablo;

final class IdleTimeoutInput
{
    public static function parse(mixed $input): int
    {
        if (!is_string($input) || strlen($input) > 16_384 || preg_match('/^[0-9]+$/D', $input) !== 1) { self::refuse(); }
        $decimal = ltrim($input, characters: '0');
        if ($decimal === '' || strlen($decimal) > 3 || (strlen($decimal) === 3 && strcmp($decimal, '720') > 0)) { self::refuse(); }
        return (int) $decimal;
    }

    private static function refuse(): never
    { throw ValidationException::field('session_idle_timeout_minutes', 'Укажите целое число минут от 1 до 720.'); }
}
