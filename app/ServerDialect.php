<?php
declare(strict_types=1);

namespace Tablo;

/** Call sites own both finite statements. SQL is never rewritten or inferred. */
final class ServerDialect
{
    public static function choose(\PDO $db, string $sqlite, string $server): string
    {
        return DatabaseSession::server($db) ? $server : $sqlite;
    }

    public static function utc(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
