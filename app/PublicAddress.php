<?php
declare(strict_types=1);

namespace Tablo;

final class PublicAddress
{
    public static function test(#[\SensitiveParameter] string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $binary = inet_pton($address);
        // Conservative IANA special-purpose policy, reviewed 2026-10-11. Even
        // globally reachable exceptions within special blocks remain excluded.
        $blocked = PublicAddressRanges::ranges($binary);
        if ($blocked === null) { return false; }
        foreach ($blocked as $range) { if (self::inRange($binary, $range)) { return false; } }
        return true;
    }

    public static function inRange(#[\SensitiveParameter] string $binary, string $range): bool
    {
        [$network, $prefix] = explode('/', $range);
        $network = inet_pton($network);
        $bits = (int) $prefix;
        $bytes = intdiv($bits, num2: 8);
        $remaining = $bits % 8;
        return substr($binary, offset: 0, length: $bytes) === substr($network, offset: 0, length: $bytes)
            && ($remaining === 0 || (ord($binary[$bytes]) & (255 << (8 - $remaining))) === ord($network[$bytes]));
    }
    public static function legacy(#[\SensitiveParameter] string $address): bool
    {
        return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !str_starts_with(strtolower($address), '::ffff:');
    }
}
