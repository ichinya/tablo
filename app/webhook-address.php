<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookAddress
{
    public static function parts(#[\SensitiveParameter] string $url): array
    {
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\<>"{}|^]/', $url)) { self::invalid(); }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { self::invalid(); }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (strlen($host) > 253 || !str_contains($host,'.') || str_ends_with($host,'.')
                || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD',$host) !== 1) { self::invalid(); }
        }
        return $parts;
    }

    public static function globallyRoutable(string $address): bool
    {
        if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)) { return false; }
        $binary = inet_pton($address);
        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            // Global filter alone admits multicast in supported PHP runtimes.
            return $first < 224 && !str_starts_with($address,'192.0.0.') && !str_starts_with($address,'192.88.99.');
        }
        // Only currently allocated global unicast 2000::/3; reject documentation,
        // transition tunnels and special-purpose ranges, including mapped addresses.
        return (ord($binary[0]) & 0xe0) === 0x20
            && !(substr($binary,0,2)==="\x20\x01" && (ord($binary[2]) & 0xfe)===0)
            && substr($binary,0,4)!=="\x20\x01\x0d\xb8"
            && substr($binary,0,2)!=="\x20\x02"
            && substr($binary,0,2)!=="\x3f\xff";
    }

    private static function invalid(): never
    {
        throw new ValidationException(['notifications' => 'Требуется корректный адрес HTTPS длиной до 500 байт без учётных данных.']);
    }
}
