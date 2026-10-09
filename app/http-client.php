<?php
declare(strict_types=1);

namespace Tablo;

class HttpClient
{
    public function __construct(private readonly bool $allowPrivate = false) {}

    public function get(string $url, array $headers = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpFailure('invalid-url');
        }
        $host = trim($parts['host'], '[]');
        $resolve = [];
        if (!$this->allowPrivate) {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
            if (!$addresses) {
                throw new HttpFailure('dns');
            }
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || str_starts_with(strtolower($address), '::ffff:')) {
                    throw new HttpFailure('ssrf');
                }
            }
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
                $address = $addresses[0];
                $resolve[] = "$host:$port:" . (str_contains($address, ':') ? "[$address]" : $address);
            }
        }
        $curl = curl_init($url);
        $body = '';
        $tooLarge = false;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Tablo/0.1',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            if ($ok === false) {
                throw $tooLarge ? new HttpFailure('size')
                    : HttpFailure::fromCurl(curl_errno($curl), (int) curl_getinfo($curl, CURLINFO_OS_ERRNO));
            }
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) { throw new HttpFailure('invalid-response'); }
            return ['status' => $status, 'body' => $body,
                'time_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000)];
        } finally {
            // The handle is released by PHP when it leaves scope.
            unset($curl);
        }
    }

    protected function resolve(string $host): array
    {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }
        return array_unique($addresses);
    }
}
