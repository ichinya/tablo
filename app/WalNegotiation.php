<?php
declare(strict_types=1);

namespace Tablo;

final class WalNegotiation
{
    public static function ensureWal(\PDO $db): void
    {
        // Own one cooperative budget; synchronous driver/filesystem calls cannot be preempted.
        $deadline = MonotonicTime::integer(hrtime(true)) + 5_000_000_000;
        $failure = null;
        $lastBusy = null;
        $delay = 5_000;
        try {
            // Do not stack a native busy-handler wait onto each application retry.
            if ($db->exec('PRAGMA busy_timeout = 0') === false) {
                throw new \RuntimeException('Cannot configure SQLite WAL negotiation timeout.');
            }
            while (true) {
                $error = null;
                $cleanupFailed = false;
                foreach (['PRAGMA main.journal_mode', 'PRAGMA main.journal_mode = WAL'] as $sql) {
                    if (MonotonicTime::integer(hrtime(true)) >= $deadline) {
                        throw $lastBusy ?? new \RuntimeException('SQLite WAL negotiation budget expired.');
                    }
                    $attempt = WalAttempt::run($db,$sql);
                    $error = $attempt->error; $cleanupFailed = $attempt->cleanupFailed;
                    if ($error !== null) { break; }
                    if ($attempt->mode === 'wal') { break 2; } // Already WAL: no setter or transaction.
                    if ($sql === 'PRAGMA main.journal_mode = WAL') {
                        throw new \RuntimeException('SQLite WAL is required for file databases; use writable local storage.');
                    }
                }
                $lastBusy = WalRetryAdmission::error($attempt);
                $remaining = $deadline - MonotonicTime::integer(hrtime(true));
                if ($remaining <= 0) { throw $lastBusy; }
                // The remaining budget is positive, and the capped delay is non-negative.
                /** @var non-negative-int $backoff */
                $backoff = min($delay, (int) ($remaining / 1_000));
                usleep($backoff);
                $delay = min($delay * 2, 25_000);
                // The next attempt checks again after waking; the deadline never resets.
            }
        } catch (\Throwable $error) {
            $failure = $error;
        }
        // Both success and every caught failure reach restoration before return/rethrow.
        try {
            if ($db->exec('PRAGMA busy_timeout = 5000') === false) {
                throw new \RuntimeException('Cannot restore SQLite busy timeout.');
            }
        } catch (\Throwable $restoreError) {
            $failure ??= $restoreError;
        }
        if ($failure !== null) { throw $failure; }
    }
}
