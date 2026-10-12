<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointAuthority
{
    public static function parse(#[\SensitiveParameter] array|false $parts): array
    {
        UriReference::validate($parts);
        if (!(($parts['scheme'] ?? null) !== null && ($parts['host'] ?? null) !== null)) { throw new HttpFailure('invalid-url'); }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], strict: true)) { throw new HttpFailure('invalid-url'); }
        $host = EndpointHost::canonical($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65_535) { throw new HttpFailure('invalid-url'); }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'origin' => $scheme . '://' . $host . ':' . $port];
    }

}
