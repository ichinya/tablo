<?php
declare(strict_types=1);

namespace Tablo;

final class WalAttempt
{
    public static function run(\PDO $db,string $sql): WalAttemptResult
    {
        $error = null; $cleanupFailed = false; $mode = null;
        $statement = null;
                    try {
                        $statement = $db->prepare($sql);
                        if ($statement === false || !$statement->execute()) {
                            throw new \RuntimeException('Cannot execute SQLite WAL negotiation.');
                        }
                        $mode = $statement->fetchColumn();
                    } catch (\Throwable $attemptError) {
                        $error = $attemptError;
                    }
                    try {
                        if ($statement !== null && $statement !== false && !$statement->closeCursor()) {
                            throw new \RuntimeException('Cannot release SQLite WAL cursor.');
                        }
                    } catch (\Throwable $cleanupError) {
                        $cleanupFailed = true;
                        $error ??= $cleanupError;
                    }
                    unset($statement);
        return new WalAttemptResult($error,$cleanupFailed,$mode);
    }
}
