<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SchemaLogic
{
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (!SchemaReference::matches($value, $rule, $root)) { return false; }
        if (($rule['anyOf'] ?? null) !== null) {
            $valid = false;
            foreach ($rule['anyOf'] as $branch) { $valid = $valid || CheckSchema::matches($value, $branch, $root); }
            if (!$valid) { return false; }
        }
        foreach ($rule['allOf'] ?? [] as $branch) { if (!CheckSchema::matches($value, $branch, $root)) { return false; } }
        if (($rule['if'] ?? null) !== null) {
            $branch = CheckSchema::matches($value, $rule['if'], $root) ? ($rule['then'] ?? []) : ($rule['else'] ?? []);
            if (!CheckSchema::matches($value, $branch, $root)) { return false; }
        }
        return true;
    }
}
