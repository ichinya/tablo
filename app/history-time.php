<?php
declare(strict_types=1);

namespace Tablo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class HistoryTime
{
    public static function canonical(mixed $input): string
    {
        if (!is_string($input) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$/D', $input)
            || substr($input, 0, 4) === '0000') {
            throw new InvalidArgumentException('Invalid history time.');
        }
        $value = str_ends_with($input, 'Z') ? substr($input, 0, -1) . '+00:00' : $input;
        if ((int) substr($value, 20, 2) > 23 || (int) substr($value, 23, 2) > 59) {
            throw new InvalidArgumentException('Invalid history time.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:sP') !== $value) {
            throw new InvalidArgumentException('Invalid history time.');
        }
        $utc = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        if (strlen($utc) !== 20 || substr($utc, 0, 4) === '0000') {
            throw new InvalidArgumentException('Invalid history time.');
        }
        return $utc;
    }
}
