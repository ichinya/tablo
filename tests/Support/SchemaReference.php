<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

/** @phpstan-import-type Rule from CheckSchema */
final class SchemaReference
{
    /**
     * @param Rule $rule
     * @param Rule $root
     */
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if (($rule['$ref'] ?? null) !== null) {
            // The published schema has only #/$defs/{site,observation,gitField} references.
            if (!str_starts_with($rule['$ref'], '#/$defs/')) { throw new \LogicException('Unsupported schema reference'); }
            $name = substr($rule['$ref'], offset: 8);
            $target = $root['$defs'][$name] ?? throw new \LogicException('Missing schema definition');
            if (!CheckSchema::matches($value, $target, $root)) { return false; }
        }
        return true;
    }
}
