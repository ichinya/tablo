<?php
declare(strict_types=1);

namespace Tablo;

use JsonException;
use RuntimeException;

final class JsonField
{
    public const OPERATORS = ['>', '>=', '<', '<=', '!=', '==', 'contains'];
    public const MAX_PATH_BYTES = 512;
    public const MAX_EXPECTED_LENGTH = 512;

    public static function validatePath(#[\SensitiveParameter] string $path): void
    {
        JsonPath::segments($path);
    }

    public static function decode(#[\SensitiveParameter] string $body): mixed
    {
        try {
            return json_decode($body, associative: false, depth: 16, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new RuntimeException('Ответ не является корректным JSON допустимой глубины.');
        }
    }








    public static function extract(#[\SensitiveParameter] mixed $document, #[\SensitiveParameter] string $path): string|int|float|bool { return JsonPathValue::extract($document, $path); }
    public static function compare(#[\SensitiveParameter] string|int|float|bool $value, string $operator, #[\SensitiveParameter] string $expected): bool { return JsonComparison::compare($value, $operator, $expected); }
    public static function number(#[\SensitiveParameter] string $value): int|float { return JsonNumeric::number($value); }
}
