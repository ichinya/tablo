<?php
declare(strict_types=1);

namespace Tablo;

final class HttpLocationHeader
{
    public static function consume(#[\SensitiveParameter] string $line, int $colon, HttpResponseHeaders $headers): void
    {
        $value = trim(substr($line, $colon + 1), characters: " \t\r\n");
        if ($headers->location !== null || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) { $headers->locationInvalid = true; }
        $headers->location = $value;
    }

}
