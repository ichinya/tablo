<?php
declare(strict_types=1);

namespace Tablo;

final class GetTarget
{
    public static function parts(#[\SensitiveParameter] string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], strict: true)
            || !($parts['host'] ?? null) || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null)) {
            throw new HttpFailure('invalid-url');
        }
        return $parts;
    }

}
