<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Validates every assertion keyword used by the published, dependency-free v1 schema. */
final class CheckSchema
{
    public static function valid(mixed $value): bool
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, levels: 2) . '/contracts/check-output-v1.schema.json'), associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        return self::matches($value, $schema, $schema);
    }

    public static function matches(mixed $value, array $rule, array $root): bool
    {

        $supported = ['$schema', 'title', '$defs', '$ref', 'type', 'required', 'additionalProperties', 'properties',
            'anyOf', 'allOf', 'if', 'then', 'else', 'enum', 'const', 'pattern', 'minimum', 'maximum', 'items'];
        if (array_diff(array_keys($rule), $supported) !== []) { throw new \LogicException('Unsupported schema assertion.'); }
        if (!SchemaLogic::matches($value, $rule, $root)) { return false; }
        if (!SchemaScalar::matches($value, $rule, $root)) { return false; }
        if (!SchemaObject::matches($value, $rule, $root)) { return false; }
        if (!SchemaItems::matches($value, $rule, $root)) { return false; }
        return SchemaBounds::matches($value, $rule);
    }
}
