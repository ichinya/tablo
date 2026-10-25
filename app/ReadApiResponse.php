<?php
declare(strict_types=1);

namespace Tablo;

final class ReadApiResponse
{
    public function __construct(public readonly int $status, public readonly string $body) {}

    public static function error(string $code): self
    {
        [$status, $message] = match ($code) {
            'unauthorized' => [401, 'Authentication required.'],
            'invalid_parameter' => [400, 'Invalid request.'],
            'not_found' => [404, 'Not found.'],
            'method_not_allowed' => [405, 'Method not allowed.'],
            default => [500, 'Service unavailable.'],
        };
        if ($status === 500) { $code = 'unavailable'; }
        return new self($status, '{"api_version":1,"error":{"code":"' . $code . '","message":"' . $message . '"}}');
    }

    public static function success(array $data): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($encoded === false) { throw new \RuntimeException('Service unavailable.'); }
        if (strlen($encoded) > 262_144) { throw new \RuntimeException('Service unavailable.'); }
        return new self(200, $encoded);
    }

    public function send(?string $hstsValue = null): void
    {
        if ($hstsValue !== null) { header('Strict-Transport-Security: ' . $hstsValue, replace: true); }
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if ($this->status === 401) { header('WWW-Authenticate: Bearer realm="tablo-read-api"'); }
        if ($this->status === 405) { header('Allow: GET'); }
        echo $this->body;
    }
}
