<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SchemaScalar
{
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (array_key_exists('const', $rule) && $value !== $rule['const']) { return false; }
        if ((($rule['enum'] ?? null) !== null) && !in_array($value, $rule['enum'], strict: true)) { return false; }
        return SchemaType::matches($value, $rule);
    }
}
