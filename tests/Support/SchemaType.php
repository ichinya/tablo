<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class SchemaType
{
    public static function matches(mixed $value, array $rule): bool
    {
        if (($rule['type'] ?? null) !== null) {
            $correct = match ($rule['type']) {
                'object' => $value instanceof \stdClass, 'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value), 'integer' => is_int($value), 'boolean' => is_bool($value), 'null' => $value === null,
                default => throw new \LogicException('Unsupported schema type.'),
            };
            if (!$correct) { return false; }
        }
        return true;
    }
}
