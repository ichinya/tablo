<?php
declare(strict_types=1);

namespace Tablo;

final class PublicDns
{
    /** @return list<string> */
    public static function addresses(#[\SensitiveParameter] string $host): array
    {
        $addresses = [];
        $records = NativeWarning::ignore(static fn () => dns_get_record($host, DNS_A | DNS_AAAA));
        if (!is_array($records)) { return []; }
        foreach (array_keys($records) as $index) {
            $address = self::address($records[$index] ?? null);
            if ($address === null) { continue; }
            $addresses[] = $address;

        }
        return array_values(array_unique($addresses));
    }

    private static function address(mixed $record): ?string
    {
        if (!is_array($record)) { return null; }
        return self::text($record['ip'] ?? $record['ipv6'] ?? null);
    }

    private static function text(mixed $value): ?string { return is_string($value) ? $value : null; }
}
