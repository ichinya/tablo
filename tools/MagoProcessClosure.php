<?php
declare(strict_types=1);

namespace Tablo\Tools;

/** Exceptional cleanup is independent of the successful child's settled exit. */
final class MagoProcessClosure
{
    /** @param resource|false|null $process */
    public static function close(mixed $process): void
    {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
}
