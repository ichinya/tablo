<?php
declare(strict_types=1);

namespace Tablo;

final class WalRetryAdmission
{
    public static function error(WalAttemptResult $attempt): \PDOException
    {
        // Cleanup failure never enters contention retry even when the primary error is BUSY.
        $error = $attempt->error;
        if ($attempt->cleanupFailed || !$error instanceof \PDOException
            || !in_array($error->errorInfo[1] ?? null,[5,261,773],strict: true)) { throw $error; }
        return $error;
    }
}
