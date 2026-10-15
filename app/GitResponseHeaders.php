<?php
declare(strict_types=1);

namespace Tablo;

final class GitResponseHeaders
{
    public static function consume(string $name, #[\SensitiveParameter] string $line, int $colon, array &$headers): void
    {
        if (!in_array($name, ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining',
            'x-ratelimit-used', 'x-ratelimit-reset', 'x-ratelimit-resource'], strict: true)) { return; }
        $value = trim(substr($line, $colon + 1));
        $valid = $name === 'x-ratelimit-resource' ? in_array($value, ['core', 'search'], strict: true)
            : (bool) preg_match('/^[0-9]{1,18}$/D', $value);
        $headers[$name] = !array_key_exists($name, $headers) && $valid ? $value : null;
    }

}
