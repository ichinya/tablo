<?php
declare(strict_types=1);

namespace Tablo;

final class EndpointHost
{
    public static function canonical(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, offset: 1, length: -1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) { throw new HttpFailure('invalid-url'); }
            $binary = inet_pton($address);
            if ($binary === false || strlen($binary) !== 16) { throw new HttpFailure('invalid-url'); }
            return '[' . inet_ntop($binary) . ']';
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Reject alternate numeric spellings rather than letting cURL interpret them.
            if (strlen($host) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z/', $host)
                || preg_match('/(?:\A|\.)(?:[0-9]+|0x[0-9a-f]+)\z/', $host)) {
                throw new HttpFailure('invalid-url');
            }
            foreach (explode('.', $host) as $label) {
                if (strlen($label) > 63) { throw new HttpFailure('invalid-url'); }
            }
        }
        return $host;
    }
}
