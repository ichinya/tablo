<?php
declare(strict_types=1);

namespace Tablo;

final class TrustedProxyChain
{
    /** @param list<string> $proxies */
    public static function resolve(array $server, array $proxies): string
    {
        $peer = IpAddress::canonical($server['REMOTE_ADDR'] ?? null);
        if ($peer === null) {
            return 'unknown';
        }
        if (!in_array($peer, $proxies, strict: true)) {
            return $peer;
        }
        if (!is_string($server['HTTP_X_FORWARDED_FOR'] ?? null)) { throw new ValidationException(['password' => ClientAddress::HEADER_ERROR]); }
        $header = $server['HTTP_X_FORWARDED_FOR'];
        if ($header === '' || strlen($header) > ClientAddress::MAX_BYTES
            || substr_count($header, needle: ',') >= ClientAddress::MAX_HOPS) {
            throw new ValidationException(['password' => ClientAddress::HEADER_ERROR]);
        }
        $chain = [];
        // Validate even unauthoritative history before selecting a client.
        foreach (explode(',', $header) as $entry) {
            $address = IpAddress::canonical(trim($entry, characters: " \t"));
            if ($address === null) {
                throw new ValidationException(['password' => ClientAddress::HEADER_ERROR]);
            }
            $chain[] = $address;
        }
        $current = $peer;
        for ($i = count($chain) - 1; $i >= 0; --$i) {
            if (!in_array($current, $proxies, strict: true)) {
                return $current;
            }
            $current = $chain[$i] ?? throw new ValidationException(['password' => ClientAddress::HEADER_ERROR]);
        }
        if (in_array($current, $proxies, strict: true)) {
            throw new ValidationException(['password' => ClientAddress::HEADER_ERROR]);
        }
        return $current;
    }
}
