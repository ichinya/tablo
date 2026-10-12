<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;


final class SchemaObject
{
    public static function matches(mixed $value, array $rule, array $root): bool
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if (array_diff($rule['required'] ?? [], array_keys($properties)) !== []) { return false; }
            if (($rule['additionalProperties'] ?? true) === false && array_diff(array_keys($properties), array_keys($rule['properties'] ?? [])) !== []) { return false; }
            foreach ($rule['properties'] ?? [] as $name => $child) {
                if (array_key_exists($name, $properties) && !CheckSchema::matches($properties[$name], $child, $root)) { return false; }
            }
        }
        return true;
    }
}
