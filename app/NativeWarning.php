<?php
declare(strict_types=1);

namespace Tablo;

/** Native best-effort operations must not emit paths or resolver diagnostics. */
final class NativeWarning
{
    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function ignore(#[\SensitiveParameter] \Closure $operation): mixed
    {
        set_error_handler(static fn (): bool => true);
        try { return $operation(); }
        finally { restore_error_handler(); }
    }
}
