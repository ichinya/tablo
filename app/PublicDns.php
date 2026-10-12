<?php
declare(strict_types=1);

namespace Tablo;

final class PublicDns
{
    public static function addresses(#[\SensitiveParameter] string $host): array
    {
        $addresses = [];
        $records = NativeWarning::ignore(static fn () => dns_get_record($host, DNS_A | DNS_AAAA));
        if (!is_array($records)) { return []; }
        foreach ($records as $record) {
            if (!((($record['ip'] ?? null) !== null) || (($record['ipv6'] ?? null) !== null))) { continue; }

                $addresses[] = $record['ip'] ?? $record['ipv6'];

        }
        return array_unique($addresses);
    }
}
