<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Authority array{scheme: string, host: string, port: int, origin: string} */
final class EndpointAuthority
{
    /** @return Authority */
    public static function parse(#[\SensitiveParameter] array|false $parts): array
    {
        UriReference::validate($parts);
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null)) { throw new HttpFailure('invalid-url'); }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], strict: true)) { throw new HttpFailure('invalid-url'); }
        $host = EndpointHost::canonical($parts['host']);
        $port = self::port($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'origin' => $scheme . '://' . $host . ':' . $port];
    }

    private static function port(mixed $port): int
    {
        if (!is_int($port) || $port < 1 || $port > 65_535) { throw new HttpFailure('invalid-url'); }
        return $port;
    }

}
