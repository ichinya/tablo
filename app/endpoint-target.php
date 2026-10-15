<?php
declare(strict_types=1);

namespace Tablo;

// URI references only. HTTP framing remains the native libcurl decoder's responsibility.
final class EndpointTarget
{
    public static function parse(#[\SensitiveParameter] string $url): array
    {
        self::syntax($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new HttpFailure('invalid-url');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) { throw new HttpFailure('invalid-url'); }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $binary = @inet_pton(substr($host, 1, -1));
            if ($binary === false || strlen($binary) !== 16) { throw new HttpFailure('invalid-url'); }
            $host = '[' . inet_ntop($binary) . ']';
        } elseif (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Reject alternate numeric spellings rather than letting cURL interpret them.
            if (strlen($host) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z/', $host)
                || preg_match('/(?:\A|\.)(?:[0-9]+|0x[0-9a-f]+)\z/', $host)) {
                throw new HttpFailure('invalid-url');
            }
            foreach (explode('.', $host) as $label) {
                if (strlen($label) > 63) { throw new HttpFailure('invalid-url'); }
            }
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) { throw new HttpFailure('invalid-url'); }
        $path = self::removeDots($parts['path'] ?? '/');
        if ($path === '') { $path = '/'; }
        if (!str_starts_with($path, '/')) { throw new HttpFailure('invalid-url'); }
        $origin = $scheme . '://' . $host . ':' . $port;
        $canonical = $origin . $path . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '');
        return ['url' => $canonical, 'origin' => $origin, 'scheme' => $scheme,
            'host' => trim($host, '[]'), 'port' => $port, 'path' => $path, 'query' => $parts['query'] ?? null];
    }

    public static function redirect(#[\SensitiveParameter] array $base, #[\SensitiveParameter] string $reference): array
    {
        try {
            self::syntax($reference);
            $parts = parse_url($reference);
            if (!is_array($parts) || isset($parts['fragment'])
                || isset($parts['user']) || isset($parts['pass'])) { throw new HttpFailure('invalid-url'); }
            if (isset($parts['scheme'])) { return self::parse($reference); }
            if (str_starts_with($reference, '//')) { return self::parse($base['scheme'] . ':' . $reference); }
            $path = $parts['path'] ?? '';
            if ($path === '') {
                $path = $base['path'];
                $query = array_key_exists('query', $parts) ? $parts['query'] : $base['query'];
            } else {
                if (!str_starts_with($path, '/')) {
                    $path = substr($base['path'], 0, strrpos($base['path'], '/') + 1) . $path;
                }
                $query = $parts['query'] ?? null;
            }
            return self::parse($base['origin'] . self::removeDots($path) . ($query !== null ? '?' . $query : ''));
        } catch (\Throwable $error) {
            unset($error);
            throw new HttpFailure('redirect-location-invalid');
        }
    }

    private static function syntax(#[\SensitiveParameter] string $value): void
    {
        if ($value === '' || strlen($value) > 8192 || preg_match('/[^A-Za-z0-9\-._~!$&\x27()*+,;=:@\/?%\[\]]/', $value)
            || preg_match('/%(?![0-9a-fA-F]{2})/', $value)) { throw new HttpFailure('invalid-url'); }
    }

    private static function removeDots(#[\SensitiveParameter] string $input): string
    {
        // RFC 3986 section 5.2.4; encoded dots/separators are left encoded.
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) { $input = substr($input, 3); }
            elseif (str_starts_with($input, './')) { $input = substr($input, 2); }
            elseif (str_starts_with($input, '/./')) { $input = substr($input, 2); }
            elseif ($input === '/.') { $input = '/'; }
            elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = $input === '/..' ? '/' : substr($input, 3);
                $last = strrpos($output, '/');
                $output = $last === false ? '' : substr($output, 0, $last);
            } elseif ($input === '.' || $input === '..') { $input = ''; }
            else {
                $next = strpos($input, '/', str_starts_with($input, '/') ? 1 : 0);
                $length = $next === false ? strlen($input) : $next;
                $output .= substr($input, 0, $length);
                $input = substr($input, $length);
            }
        }
        return $output;
    }

    public static function isPublicAddress(#[\SensitiveParameter] string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $binary = inet_pton($address);
        // Conservative IANA special-purpose policy, reviewed 2026-10-11. Even
        // globally reachable exceptions within special blocks remain excluded.
        if (strlen($binary) === 4) {
            $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        } else {
            // Only global unicast 2000::/3; excludes mapped/translation, zones,
            // multicast, ULA/link-local and future unallocated address formats.
            if (!self::inRange($binary, '2000::/3')) { return false; }
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20'];
        }
        foreach ($blocked as $range) { if (self::inRange($binary, $range)) { return false; } }
        return true;
    }

    private static function inRange(#[\SensitiveParameter] string $binary, string $range): bool
    {
        [$network, $prefix] = explode('/', $range);
        $network = inet_pton($network);
        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        return substr($binary, 0, $bytes) === substr($network, 0, $bytes)
            && ($remaining === 0 || (ord($binary[$bytes]) & (255 << (8 - $remaining))) === ord($network[$bytes]));
    }
}
