<?php
declare(strict_types=1);

namespace Tablo;

final class JsonPathValue
{
    public static function extract(#[\SensitiveParameter] mixed $document, #[\SensitiveParameter] string $path): string|int|float|bool
    {
        return self::walk($document, JsonPath::segments($path), 0);
    }

    /** @param list<array{0: 'key', 1: string}|array{0: 'index', 1: int}> $segments */
    private static function walk(#[\SensitiveParameter] mixed $document, array $segments, int $offset): string|int|float|bool
    {
        if (!array_key_exists($offset, $segments)) { return JsonPathScalar::checked($document); }
        $segment = $segments[$offset];
        if ($segment[0] === 'key' && $document instanceof \stdClass) {
            $properties = get_object_vars($document);
            if (array_key_exists($segment[1], $properties)) {
                return self::walk($properties[$segment[1]], $segments, $offset + 1);
            }
        }
        if ($segment[0] === 'index' && is_array($document) && array_key_exists($segment[1], $document)) {
            return self::walk($document[$segment[1]], $segments, $offset + 1);
        }
        throw new \RuntimeException('Поле по JSON path не найдено.');
    }
}
