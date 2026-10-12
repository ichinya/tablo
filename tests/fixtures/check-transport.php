<?php
declare(strict_types=1);

namespace Tablo;

// Actual CLI/checker/connection/provider/policy/cURL; only Git DNS/origin are synthetic.
function dns_get_record(string $host, int $type = DNS_ANY): array|false
{
    if ($host === 'api.github.com') { return [['type' => 'A', 'ip' => '93.184.216.34']]; }
    throw new \RuntimeException('Unexpected synthetic DNS target.');
}

function curl_init(?string $url = null): \CurlHandle|false
{
    if (is_string($url) && str_starts_with($url, 'https://api.github.com/')) {
        $url = getenv('TABLO_CHECK_PEER') . substr($url, strlen('https://api.github.com'));
    }
    return \curl_init($url);
}

function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    static $failed = false;
    if (!$failed && in_array(getenv('TABLO_CHECK_INJECT'), ['encode', 'encode-after-store'], strict: true)
        && is_array($value) && ($value['id'] ?? null) === 2
        && (getenv('TABLO_CHECK_INJECT') === 'encode' || ($value['persistence']['accepted'] ?? null) === true)) {
        $failed = true;
        throw new \JsonException('Synthetic encoding failure.');
    }
    return \json_encode($value, $flags, $depth);
}

function curl_getinfo(\CurlHandle $handle, ?int $option = null): mixed
{
    static $times = 0;
    if ($option === CURLINFO_TOTAL_TIME) {
        ++$times;
        if (getenv('TABLO_CHECK_INJECT') === 'scalar' && $times === 7) { return -1.0; }
    }
    return \curl_getinfo($handle, $option);
}

function gmdate(string $format, ?int $timestamp = null): string
{
    static $calls = 0;
    ++$calls;
    if (getenv('TABLO_CHECK_INJECT') === 'timestamp' && $calls === 3) { return "invalid-\xff-time"; }
    return \gmdate($format, $timestamp);
}
