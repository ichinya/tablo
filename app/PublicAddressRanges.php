<?php
declare(strict_types=1);

namespace Tablo;

final class PublicAddressRanges
{
    public static function ranges(#[\SensitiveParameter] string $binary): ?array
    {
        if (strlen($binary) === 4) {
            return ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        }

            // Only global unicast 2000::/3; excludes mapped/translation, zones,
            // multicast, ULA/link-local and future unallocated address formats.
            if (!PublicAddress::inRange($binary, '2000::/3')) { return null; }
            return ['2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20'];
    }
}
