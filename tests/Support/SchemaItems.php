<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


/** @phpstan-import-type Rule from CheckSchema */
final class SchemaItems
{
    /**
     * @param Rule $rule
     * @param Rule $root
     */
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (is_array($value) && (($rule['items'] ?? null) !== null)) {
            foreach (array_keys($value) as $index) { if (!CheckSchema::matches($value[$index] ?? null, $rule['items'], $root)) { return false; } }
        }
        return true;
    }
}
