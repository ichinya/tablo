<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

final class JsonField
{
    public const OPERATORS = ['>', '>=', '<', '<=', '!=', '==', 'contains'];
    public const MAX_PATH_BYTES = 512;
    public const MAX_EXPECTED_LENGTH = 512;

    public static function validatePath(string $path): void
    {
        self::segments($path);
    }

    public static function decode(string $body): mixed
    {
        try {
            return json_decode($body, associative: false, depth: 16, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new RuntimeException('Ответ не является корректным JSON допустимой глубины.');
        }
    }

    public static function extract(mixed $document, string $path): string|int|float|bool
    {
        $value = $document;
        foreach (self::segments($path) as [$kind, $key]) {
            if ($kind === 'key' && $value instanceof stdClass && property_exists($value, $key)) {
                $value = $value->{$key};
                continue;
            }
            if ($kind === 'index' && is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
                continue;
            }
            throw new RuntimeException('Поле по JSON path не найдено.');
        }
        if ($value === null) {
            throw new RuntimeException('JSON-поле содержит null.');
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new RuntimeException('JSON path должен выбирать одно значение, а не объект или массив.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new RuntimeException('JSON-поле содержит некорректное число.');
        }
        return $value;
    }

    public static function compare(string|int|float|bool $value, string $operator, string $expected): bool
    {
        if (!in_array($operator, self::OPERATORS, strict: true)) {
            throw new InvalidArgumentException('Выберите допустимое условие JSON-проверки.');
        }
        $actual = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
        return match ($operator) {
            '==' => $actual === $expected,
            '!=' => $actual !== $expected,
            'contains' => str_contains($actual, $expected),
            '>' => self::number($actual) > self::number($expected),
            '>=' => self::number($actual) >= self::number($expected),
            '<' => self::number($actual) < self::number($expected),
            '<=' => self::number($actual) <= self::number($expected),
        };
    }

    public static function number(string $value): int|float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException('Для этого условия нужно конечное число.');
        }
        return +$value;
    }

    private static function segments(string $path): array
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES || $path[0] !== '$') {
            throw new InvalidArgumentException('Укажите JSON path от $, до 512 байт.');
        }
        $segments = [];
        $offset = 1;
        $length = strlen($path);
        while ($offset < $length) {
            if (preg_match('/\G\.([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $match, flags: 0, offset: $offset)) {
                $segments[] = ['key', $match[1]];
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G\[(0|[1-9][0-9]*)\]/', $path, $match, flags: 0, offset: $offset)) {
                $index = filter_var($match[1], FILTER_VALIDATE_INT);
                if ($index === false) {
                    throw new InvalidArgumentException('Индекс JSON-массива слишком большой.');
                }
                $segments[] = ['index', $index];
                $offset += strlen($match[0]);
                continue;
            }
            if (substr($path, $offset, length: 2) === '["') {
                $start = $offset + 1;
                $cursor = $start + 1;
                while ($cursor < $length && $path[$cursor] !== '"') {
                    $cursor += $path[$cursor] === '\\' ? 2 : 1;
                }
                if ($cursor >= $length || ($path[$cursor + 1] ?? '') !== ']') {
                    throw new InvalidArgumentException('Некорректный ключ в JSON path.');
                }
                try {
                    $key = json_decode(substr($path, $start, $cursor - $start + 1), associative: false, depth: 2, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new InvalidArgumentException('Некорректный ключ в JSON path.');
                }
                $segments[] = ['key', $key];
                $offset = $cursor + 2;
                continue;
            }
            throw new InvalidArgumentException('JSON path: используйте .поле, [индекс] или ["ключ"].');
        }
        return $segments;
    }
}
