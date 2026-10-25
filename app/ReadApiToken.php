<?php
declare(strict_types=1);

namespace Tablo;

final class ReadApiToken
{
    public static function generate(): string { return 'tablo_read_v1_' . bin2hex(random_bytes(32)); }
    public static function digest(#[\SensitiveParameter] string $secret): string { return hash('sha256', "tablo/read-api/bearer/v1\0" . $secret); }
    public static function validDigest(#[\SensitiveParameter] mixed $value): bool { return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1; }
}
