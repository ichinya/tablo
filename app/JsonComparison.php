<?php
declare(strict_types=1);

namespace Tablo;

final class JsonComparison
{
    public static function compare(#[\SensitiveParameter] string|int|float|bool $value, string $operator, #[\SensitiveParameter] string $expected): bool
    {
        if (!in_array($operator, JsonField::OPERATORS, strict: true)) {
            throw new \InvalidArgumentException('Выберите допустимое условие JSON-проверки.');
        }
        $actual = JsonScalarText::text($value);
        return match ($operator) {
            '==' => $actual === $expected,
            '!=' => $actual !== $expected,
            'contains' => str_contains($actual, $expected),
            '>' => JsonNumeric::number($actual) > JsonNumeric::number($expected),
            '>=' => JsonNumeric::number($actual) >= JsonNumeric::number($expected),
            '<' => JsonNumeric::number($actual) < JsonNumeric::number($expected),
            '<=' => JsonNumeric::number($actual) <= JsonNumeric::number($expected),
        };
    }
}
