<?php
declare(strict_types=1);

namespace Tablo;

use RuntimeException;

/** Pure login transport policy. Forwarded aliases never establish authority. */
final class ClientAddress
{
    public const MAX_BYTES = 4096;
    public const MAX_HOPS = 32;
    public const CONFIG_ERROR = 'Invalid TABLO_TRUSTED_PROXIES configuration.';
    public const HEADER_ERROR = 'Invalid trusted proxy address chain.';

    /** @var list<string> */
    private readonly array $proxies;

    public function __construct(#[\SensitiveParameter] string|false $configuration = false)
    {
        if ($configuration === false || $configuration === '') {
            $this->proxies = [];
            return;
        }
        if (strlen($configuration) > self::MAX_BYTES || substr_count($configuration, ',') >= self::MAX_HOPS) {
            throw new RuntimeException(self::CONFIG_ERROR);
        }
        $proxies = [];
        foreach (explode(',', $configuration) as $entry) {
            $address = self::canonical(trim($entry, " \t"));
            if ($address === null || $address === '0.0.0.0' || $address === '::') {
                throw new RuntimeException(self::CONFIG_ERROR);
            }
            if (!in_array($address, $proxies, true)) {
                $proxies[] = $address;
            }
        }
        $this->proxies = $proxies;
    }

    public function resolve(array $server): string
    {
        $peer = self::canonical($server['REMOTE_ADDR'] ?? null);
        if ($peer === null) {
            return 'unknown';
        }
        if (!in_array($peer, $this->proxies, true)) {
            return $peer;
        }
        $header = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($header) || $header === '' || strlen($header) > self::MAX_BYTES
            || substr_count($header, ',') >= self::MAX_HOPS) {
            throw new ValidationException(['password' => self::HEADER_ERROR]);
        }
        $chain = [];
        // Validate even unauthoritative history before selecting a client.
        foreach (explode(',', $header) as $entry) {
            $address = self::canonical(trim($entry, " \t"));
            if ($address === null) {
                throw new ValidationException(['password' => self::HEADER_ERROR]);
            }
            $chain[] = $address;
        }
        $current = $peer;
        for ($i = count($chain) - 1; $i >= 0; --$i) {
            if (!in_array($current, $this->proxies, true)) {
                return $current;
            }
            $current = $chain[$i];
        }
        if (in_array($current, $this->proxies, true)) {
            throw new ValidationException(['password' => self::HEADER_ERROR]);
        }
        return $current;
    }

    private static function canonical(mixed $address): ?string
    {
        if (!is_string($address) || strlen($address) > 45 || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }
        // IPv4-mapped IPv6 is the same identity for trust AND limiter keys.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $canonical = inet_ntop($packed);
        return $canonical === false ? null : $canonical;
    }
}
