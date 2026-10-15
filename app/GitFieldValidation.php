<?php
declare(strict_types=1);

namespace Tablo;

final class GitFieldValidation
{
    public static function valid(string $field, #[\SensitiveParameter] mixed $value): bool
    {
        return match ($field) {
            'latest_release' => $value === null || (is_string($value) && $value !== '' && strlen($value) <= 200
                && preg_match('//u', $value) === 1 && !str_contains($value, "\0")),
            'latest_commit' => is_string($value) && preg_match('/^[a-f0-9]{7,64}$/iD', $value) === 1,
            default => true,
        };
    }

}
