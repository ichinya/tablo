<?php
declare(strict_types=1);

namespace Tablo;


final class IncidentQuery
{
    public static function integer(mixed $value, int $maximum): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)
            || strlen($value) > strlen((string) $maximum)
            || (strlen($value) === strlen((string) $maximum) && strcmp($value, (string) $maximum) > 0)) {
            throw new \InvalidArgumentException('Invalid incident query.');
        }
        return (int) $value;
    }
}
