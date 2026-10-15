<?php
declare(strict_types=1);

namespace Tablo;

final class SingletonAdministrator
{
    public static function hash(\PDO $db): string
    {
        $statement = $db->query("SELECT sql FROM main.sqlite_schema WHERE type = 'table' AND name = 'users'");
        try { $sql = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        // Accept the actual frozen users definition, including its singleton constraint.
        // An advertised user_version or a CHECK hidden in a comment is insufficient.
        $expected = "CREATE TABLE users (id INTEGER PRIMARY KEY CHECK (id = 1), password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')))";
        $normalize = static fn (string $value): string => preg_replace('/\s+/', replacement: '', subject: $value);
        if (!is_string($sql) || $normalize($sql) !== $normalize($expected)) {
            throw new InstallationException('Installation schema incompatible.');
        }
        $statement = $db->query('SELECT id, password_hash FROM main.users');
        try { $users = $statement->fetchAll(); }
        finally { $statement->closeCursor(); }
        $hash = $users[0]['password_hash'] ?? null;
        if (count($users) !== 1 || (int) $users[0]['id'] !== 1 || !is_string($hash)
            || password_get_info($hash)['algo'] === null
            || (str_starts_with($hash, '$2') && preg_match('/^\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $hash) !== 1)) {
            throw new InstallationException('Administrator unavailable.');
        }
        return $hash;
    }

}
