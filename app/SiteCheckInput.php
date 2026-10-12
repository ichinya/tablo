<?php
declare(strict_types=1);

namespace Tablo;

/** Required check text is admitted at the consuming phase, without scalar coercion. */
final class SiteCheckInput
{
    public static function text(#[\SensitiveParameter] array $site, string $field): string
    {
        if (!array_key_exists($field, $site)) { throw new \TypeError('Missing site check text.'); }
        return self::value($site[$field] ?? null);
    }

    public static function optionalText(#[\SensitiveParameter] array $site, string $field, string $default = ''): string
    {
        // Preserve the existing ?? default for absent or explicitly null optional fields.
        return self::value($site[$field] ?? $default);
    }

    private static function value(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) { throw new \TypeError('Invalid site check text.'); }
        return $value;
    }
}
