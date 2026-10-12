<?php
declare(strict_types=1);

namespace Tablo;

// URI references only. HTTP framing remains the native libcurl decoder's responsibility.
/** @phpstan-type Target array{url: string, origin: string, scheme: string, host: string, port: int, path: string, query: ?string} */
final class EndpointTarget
{
    /** @return Target */
    public static function parse(#[\SensitiveParameter] string $url): array
    {
        UriSyntax::validate($url);
        $parts = parse_url($url);
        if ($parts === false) { throw new HttpFailure('invalid-url'); }
        $authority = EndpointAuthority::parse($parts);
        $scheme = $authority['scheme']; $host = $authority['host']; $port = $authority['port'];
        $path = UriPath::removeDots($parts['path'] ?? '/');
        if ($path === '') { $path = '/'; }
        if (!str_starts_with($path, '/')) { throw new HttpFailure('invalid-url'); }
        $origin = $scheme . '://' . $host . ':' . $port;
        $canonical = $origin . $path . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '');
        return ['url' => $canonical, 'origin' => $origin, 'scheme' => $scheme,
            'host' => trim($host, characters: '[]'), 'port' => $port, 'path' => $path, 'query' => $parts['query'] ?? null];
    }










    /**
     * @param Target $base
     * @return Target
     */
    public static function redirect(#[\SensitiveParameter] array $base, #[\SensitiveParameter] string $reference): array
    {
        return EndpointRedirect::resolve($base, $reference);
    }

    public static function isPublicAddress(#[\SensitiveParameter] string $address): bool
    {
        return PublicAddress::test($address);
    }
    public static function syntax(#[\SensitiveParameter] string $value): void
    {
        UriSyntax::validate($value);
    }
}
