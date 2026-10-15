<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** Validates every assertion keyword used by the published, dependency-free v1 schema. */
final class CheckSchema
{
    public static function valid(mixed $value): bool
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2) . '/contracts/check-output-v1.schema.json'), true, 64, JSON_THROW_ON_ERROR);
        return self::matches($value, $schema, $schema);
    }

    private static function matches(mixed $value, array $rule, array $root): bool
    {
        $supported = ['$schema', 'title', '$defs', '$ref', 'type', 'required', 'additionalProperties', 'properties',
            'anyOf', 'allOf', 'if', 'then', 'else', 'enum', 'const', 'pattern', 'minimum', 'maximum', 'items'];
        if (array_diff(array_keys($rule), $supported) !== []) { throw new \LogicException('Unsupported schema assertion.'); }
        if (isset($rule['$ref'])) {
            $target = $root;
            foreach (explode('/', substr($rule['$ref'], 2)) as $part) { $target = $target[$part]; }
            if (!self::matches($value, $target, $root)) { return false; }
        }
        if (isset($rule['anyOf'])) {
            $valid = false;
            foreach ($rule['anyOf'] as $branch) { $valid = $valid || self::matches($value, $branch, $root); }
            if (!$valid) { return false; }
        }
        foreach ($rule['allOf'] ?? [] as $branch) { if (!self::matches($value, $branch, $root)) { return false; } }
        if (isset($rule['if'])) {
            $branch = self::matches($value, $rule['if'], $root) ? ($rule['then'] ?? []) : ($rule['else'] ?? []);
            if (!self::matches($value, $branch, $root)) { return false; }
        }
        if (array_key_exists('const', $rule) && $value !== $rule['const']) { return false; }
        if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) { return false; }
        if (isset($rule['type'])) {
            $correct = match ($rule['type']) {
                'object' => $value instanceof \stdClass, 'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value), 'integer' => is_int($value), 'boolean' => is_bool($value), 'null' => $value === null,
                default => throw new \LogicException('Unsupported schema type.'),
            };
            if (!$correct) { return false; }
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if (array_diff($rule['required'] ?? [], array_keys($properties)) !== []) { return false; }
            if (($rule['additionalProperties'] ?? true) === false && array_diff(array_keys($properties), array_keys($rule['properties'] ?? [])) !== []) { return false; }
            foreach ($rule['properties'] ?? [] as $name => $child) {
                if (array_key_exists($name, $properties) && !self::matches($properties[$name], $child, $root)) { return false; }
            }
        }
        if (is_array($value) && isset($rule['items'])) {
            foreach ($value as $item) { if (!self::matches($item, $rule['items'], $root)) { return false; } }
        }
        if (is_string($value) && isset($rule['pattern']) && preg_match('~' . $rule['pattern'] . '~D', $value) !== 1) { return false; }
        if (is_int($value) && ((isset($rule['minimum']) && $value < $rule['minimum'])
            || (isset($rule['maximum']) && $value > $rule['maximum']))) { return false; }
        return true;
    }
}
