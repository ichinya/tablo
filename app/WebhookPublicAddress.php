<?php
declare(strict_types=1);

namespace Tablo;

final class WebhookPublicAddress
{
    public static function globallyRoutable(string $address): bool
    {
        if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)) { return false; }
        $binary = inet_pton($address);
        if ($binary === false || strlen($binary) < 4) { return false; }
        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            // Global filter alone admits multicast in supported PHP runtimes.
            return $first < 224 && !str_starts_with($address,'192.0.0.') && !str_starts_with($address,'192.88.99.');
        }
        // Only currently allocated global unicast 2000::/3; reject documentation,
        // transition tunnels and special-purpose ranges, including mapped addresses.
        return (ord($binary[0]) & 0xe0) === 0x20
            && !(substr($binary,offset: 0,length: 2)==="\x20\x01" && (ord($binary[2]) & 0xfe)===0)
            && substr($binary,offset: 0,length: 4)!=="\x20\x01\x0d\xb8"
            && substr($binary,offset: 0,length: 2)!=="\x20\x02"
            && substr($binary,offset: 0,length: 2)!=="\x3f\xff";
    }
}
