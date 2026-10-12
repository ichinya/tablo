<?php
declare(strict_types=1);

namespace Tablo;

final class AddressRange
{
    /** @return array{network: string, bits: int}|null */
    public static function parse(string $range, string $binary): ?array
    {
        $parts = explode('/', $range);
        $prefix = $parts[1] ?? null;
        if (count($parts) !== 2 || !is_string($prefix) || !ctype_digit($prefix)) { return null; }
        $network = inet_pton($parts[0]);
        if ($network === false || strlen($network) !== strlen($binary)) { return null; }
        $bits = (int) $prefix;
        if ($bits > strlen($network) * 8) { return null; }
        return ['network' => $network, 'bits' => $bits];
    }
}
