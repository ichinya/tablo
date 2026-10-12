<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointRedirect
{
    public static function resolve(#[\SensitiveParameter] array $base, #[\SensitiveParameter] string $reference): array
    {
        try {
            EndpointTarget::syntax($reference);
            $parts = parse_url($reference);
            UriReference::validate($parts);
            if (($parts['scheme'] ?? null) !== null) { return EndpointTarget::parse($reference); }
            if (str_starts_with($reference, '//')) { return EndpointTarget::parse($base['scheme'] . ':' . $reference); }
            $resolved = UriRelativePath::against($base, $parts);
            $path = $resolved['path']; $query = $resolved['query'];
            return EndpointTarget::parse($base['origin'] . UriPath::removeDots($path) . ($query !== null ? '?' . $query : ''));
        } catch (\Throwable $error) {
            unset($error);
            throw new HttpFailure('redirect-location-invalid');
        }
    }
}
