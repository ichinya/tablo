<?php
declare(strict_types=1);

namespace Tablo;

/** Safe fixed diagnostics must never replace an already computed check outcome. */
final class CheckDiagnostics
{
    public static function write(string $message): void
    {
        set_error_handler(static fn (): bool => true);
        try { fwrite(STDERR, $message); }
        catch (\Throwable $discardedCleanup) { /* A failed OS sink or closed resource cannot change JSON or exit. */  unset($discardedCleanup); }
        finally { restore_error_handler(); }
    }
}
