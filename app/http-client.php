<?php
declare(strict_types=1);

namespace Tablo;

class HttpClient
{
    public function __construct(private readonly bool $allowPrivate = false) {}

    public function get(string $url, array $headers = []): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], strict: true)
            || !($parts['host'] ?? null) || (($parts['user'] ?? null) !== null) || (($parts['pass'] ?? null) !== null)) {
            throw new \RuntimeException('Недопустимый адрес проверки.');
        }
        $host = trim($parts['host'], characters: '[]');
        $resolve = [];
        if (!$this->allowPrivate) {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
            if (!$addresses) {
                throw new \RuntimeException('Не удалось разрешить DNS-имя.');
            }
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || str_starts_with(strtolower($address), '::ffff:')) {
                    throw new \RuntimeException('Локальный адрес: разрешите внутреннюю сеть в настройках установки.');
                }
            }
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
                $address = $addresses[0];
                $resolve[] = "{$host}:{$port}:" . (str_contains($address, ':') ? "[{$address}]" : $address);
            }
        }
        $curl = curl_init($url);
        $body = '';
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
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1_048_576) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            if ($ok === false) {
                throw new \RuntimeException('Соединение не удалось, истёк таймаут или ответ превышает 1 МБ.');
            }
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body,
                'time_ms' => (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000)];
        } finally {
            // The handle is released by PHP when it leaves scope.
            unset($curl);
        }
    }

    private function resolve(string $host): array
    {
        $addresses = [];
        // Handle the failure explicitly below; suppress raw filesystem/network warnings.
        // @mago-expect lint:no-error-control-operator
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records === false ? [] : $records as $record) {
            if (($record['ip'] ?? $record['ipv6'] ?? null) === null) { continue; }
            $addresses[] = $record['ip'] ?? $record['ipv6'];
        }
        return array_unique($addresses);
    }
}
