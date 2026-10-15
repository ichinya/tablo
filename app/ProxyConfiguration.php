<?php
declare(strict_types=1);

namespace Tablo;

final class ProxyConfiguration
{
    public static function parse(#[\SensitiveParameter] string|false $configuration = false): array
    {
        if ($configuration === false || $configuration === '') {
            return [];
        }
        if (strlen($configuration) > ClientAddress::MAX_BYTES || substr_count($configuration, needle: ',') >= ClientAddress::MAX_HOPS) {
            throw new \RuntimeException(ClientAddress::CONFIG_ERROR);
        }
        $proxies = [];
        foreach (explode(',', $configuration) as $entry) {
            $address = IpAddress::canonical(trim($entry, characters: " \t"));
            if ($address === null || $address === '0.0.0.0' || $address === '::') {
                throw new \RuntimeException(ClientAddress::CONFIG_ERROR);
            }
            if (!in_array($address, $proxies, strict: true)) {
                $proxies[] = $address;
            }
        }
        return $proxies;
    }
}
