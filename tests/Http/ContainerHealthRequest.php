<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\TestServer;
use Testo\Assert;

final class ContainerHealthRequest
{
    /**
     * @param list<string> $headers
     * @return array{status: int, headers: string, body: string}
     */
    public static function request(TestServer $server, string $path = '/_tablo/health', string $method = 'GET', array $headers = ['Host: 127.0.0.1']): array
    {
        $curl = curl_init($server->base . $path);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize health fixture request.'); }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '',
            CURLOPT_TIMEOUT => 5, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_REQUEST_TARGET => $path]);
        if ($method === 'HEAD') { curl_setopt($curl, CURLOPT_NOBODY, value: true); }
        $raw = curl_exec($curl);
        Assert::true(is_string($raw));
        $raw = \Tablo\Tests\Support\FixtureValue::string($raw);
        $size = \Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_HEADER_SIZE));
        return ['status' => \Tablo\Tests\Support\FixtureValue::integer(curl_getinfo($curl, CURLINFO_RESPONSE_CODE)), 'headers' => substr($raw, offset: 0, length: $size), 'body' => substr($raw, $size)];
    }

}
