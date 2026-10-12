<?php
declare(strict_types=1);

namespace Tablo;

final class ResourceStreams
{
    public static function close(#[\SensitiveParameter] array $streams): void
    {
        foreach (array_keys($streams) as $key) { self::closeOne($streams[$key] ?? null); }
    }

    private static function closeOne(#[\SensitiveParameter] mixed $stream): void
    {
        if (is_resource($stream)) { fclose($stream); }
    }

}
