<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

/** Dedicated ingress: neither Web nor any vault/session/worker owner is initialized. */
final class ReadApi
{
    public static function serve(): void
    { read_api_serve(); }

    public static function read(#[\SensitiveParameter] string $token, #[\SensitiveParameter] array $server): ReadApiResponse
    { return read_api_response($token, $server); }
}
