<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SchemaItems
{
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (is_array($value) && (($rule['items'] ?? null) !== null)) {
            foreach ($value as $item) { if (!CheckSchema::matches($item, $rule['items'], $root)) { return false; } }
        }
        return true;
    }
}
