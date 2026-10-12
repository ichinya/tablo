<?php
declare(strict_types=1);

namespace Tablo;

final class ResourceStreams
{
    public static function close(#[\SensitiveParameter] array $streams): void
    {
        foreach ($streams as $stream) {
            if (!is_resource($stream)) { continue; }
            fclose($stream);
        }
    }

}
