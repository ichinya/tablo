<?php
declare(strict_types=1);

namespace Tablo;

final class JsonPathValue
{
    public static function extract(#[\SensitiveParameter] mixed $document, #[\SensitiveParameter] string $path): string|int|float|bool
    {
        $value = $document;
        foreach (JsonPath::segments($path) as [$kind, $key]) {
            if ($kind === 'key' && $value instanceof \stdClass && property_exists($value, $key)) {
                $value = $value->{$key};
                continue;
            }
            if ($kind === 'index' && is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
                continue;
            }
            throw new \RuntimeException('Поле по JSON path не найдено.');
        }
        if ($value === null) {
            throw new \RuntimeException('JSON-поле содержит null.');
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new \RuntimeException('JSON path должен выбирать одно значение, а не объект или массив.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \RuntimeException('JSON-поле содержит некорректное число.');
        }
        return $value;
    }
}
