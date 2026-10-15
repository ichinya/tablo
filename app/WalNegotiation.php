<?php
declare(strict_types=1);

namespace Tablo;

final class WalNegotiation
{
    public static function ensureWal(\PDO $db): void
    {
        // Own one cooperative budget; synchronous driver/filesystem calls cannot be preempted.
        $deadline = hrtime(true) + 5_000_000_000;
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
                    if (hrtime(true) >= $deadline) {
                        throw $lastBusy ?? new \RuntimeException('SQLite WAL negotiation budget expired.');
                    }
                    $attempt = WalAttempt::run($db,$sql);
                    $error = $attempt->error; $cleanupFailed = $attempt->cleanupFailed; $mode = $attempt->mode;
                    if ($error !== null) { break; }
                    if ($mode === 'wal') { break 2; } // Already WAL: no setter or transaction.
                    if ($sql === 'PRAGMA main.journal_mode = WAL') {
                        throw new \RuntimeException('SQLite WAL is required for file databases; use writable local storage.');
                    }
                }
                $lastBusy = WalRetryAdmission::error($attempt);
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) { throw $lastBusy; }
                usleep(min($delay, (int) ($remaining / 1_000)));
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
