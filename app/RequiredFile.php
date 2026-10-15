<?php
declare(strict_types=1);

namespace Tablo;

/** Missing required bytes fail explicitly rather than becoming a coerced string. */
final class RequiredFile
{
    public static function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) { throw new \RuntimeException('Cannot hash required file.'); }
        return $digest;
    }

    public static function bytes(#[\SensitiveParameter] string|false $contents): string
    {
        if ($contents === false) { throw new \RuntimeException('Cannot read required file.'); }
        return $contents;
    }
}
