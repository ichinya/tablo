<?php
declare(strict_types=1);

namespace Tablo;

final class IpAddress
{
    public static function canonical(mixed $address): ?string
    {
        if (!is_string($address) || strlen($address) > 45 || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // IPv4-mapped IPv6 is the same identity for trust AND limiter keys.
        if (strlen($packed) === 16 && substr($packed, offset: 0, length: 12) === str_repeat("\0", times: 10) . "\xff\xff") {
            $packed = substr($packed, offset: 12);
        }
        $canonical = inet_ntop($packed);
        return $canonical === false ? null : $canonical;
    }
}
