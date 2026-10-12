<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

/** @phpstan-import-type Rule from CheckSchema */
final class SchemaBounds
{
    /** @param Rule $rule */
    public static function matches(mixed $value, array $rule): bool
    {
        if (is_string($value) && (($rule['pattern'] ?? null) !== null) && preg_match('~' . $rule['pattern'] . '~D', $value) !== 1) { return false; }
        if (is_int($value) && (((($rule['minimum'] ?? null) !== null) && $value < $rule['minimum'])
            || ((($rule['maximum'] ?? null) !== null) && $value > $rule['maximum']))) { return false; }
        return true;
    }
}
