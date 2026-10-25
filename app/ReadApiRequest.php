<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

/** Raw SAPI values, deliberately independent of Composer and browser sessions. */
final class ReadApiRequest
{
    public function __construct(public readonly ?string $id, public readonly int $limit = 50, public readonly string $afterId = '0') {}

    public static function bearer(#[\SensitiveParameter] array $server): ?string
    { return read_api_bearer($server); }

    public static function decimal(mixed $value, string $minimum = '0'): string
    { return read_api_decimal($value, $minimum); }

    /** Route errors precede parameter errors, after authentication. */
    public static function parse(#[\SensitiveParameter] array $server, int $bodyBytes = 0): self|ReadApiResponse
    { return read_api_parse($server, $bodyBytes); }
}
