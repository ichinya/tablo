<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class SchemaReference
{
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (($rule['$ref'] ?? null) !== null) {
            $target = $root;
            foreach (explode('/', substr($rule['$ref'], offset: 2)) as $part) { $target = $target[$part]; }
            if (!CheckSchema::matches($value, $target, $root)) { return false; }
        }
        return true;
    }
}
