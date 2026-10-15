<?php
declare(strict_types=1);

namespace Tablo;

final class GetTarget
{
    /** @return array{'fragment'?: string, 'host': non-empty-string, 'path'?: string, 'port'?: int<0, 65535>, 'query'?: string, 'scheme': non-empty-string} */
    public static function parts(#[\SensitiveParameter] string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], strict: true)
            || !($parts['host'] ?? null) || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null)) {
            throw new HttpFailure('invalid-url');
        }
        // The accepted scheme check establishes this required key despite parse_url's optional-key declaration.
        /** @var array{fragment?: string, host: non-empty-string, path?: string, port?: int<0, 65535>, query?: string, scheme: 'http'|'https'} $parts */
        return $parts;
    }

}
