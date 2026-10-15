<?php
declare(strict_types=1);

namespace Tablo;

final class CurlGetOptions
{
    /**
     * @param list<string> $headers
     * @param list<string> $resolve
     * @param array{path?: string, query?: string, ...}|null $parts
     */
    public static function build(#[\SensitiveParameter] array $headers, array $resolve, int $remainingMs, HttpResponseBuffer $buffer, #[\SensitiveParameter] ?array $parts): array
    {
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remainingMs),
                CURLOPT_TIMEOUT_MS => $remainingMs,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Tablo/0.1',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RESOLVE => $resolve,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static fn (#[\SensitiveParameter] $curl, #[\SensitiveParameter] string $line): int => $buffer->header($line),
                CURLOPT_WRITEFUNCTION => static fn (#[\SensitiveParameter] $curl, #[\SensitiveParameter] string $chunk): int => $buffer->write($chunk),
                ];
            if ($parts !== null) {
                // EndpointTarget already validated and canonicalized this URL.
                // libcurl otherwise drops an explicitly empty query delimiter.
                $options[CURLOPT_REQUEST_TARGET] = ($parts['path'] ?? '/')
                    . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '');
            }
        return $options;
    }

}
