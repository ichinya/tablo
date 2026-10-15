<?php
declare(strict_types=1);

namespace Tablo;

/** HTML checkbox values accepted by the existing request contract. */
final class FormCheckbox
{
    public const VALUE = '1';
    public const INTEGER_VALUE = 1;

    public static function selected(mixed $value): bool
    {
        return in_array($value, [self::INTEGER_VALUE, self::VALUE, 'on'], strict: true);
    }
}
