<?php
declare(strict_types=1);

namespace Tablo;

final class SingletonAdministrator
{
    public static function hash(\PDO $db): string
    {
        if (DatabaseSession::server($db)) {
            ServerSchema::admit($db);
            return self::validatedHash($db, 'SELECT id, password_hash FROM users');
        }
        $statement = \Tablo\SqlStatement::checked($db->query("SELECT sql FROM main.sqlite_schema WHERE type = 'table' AND name = 'users'"));
        // sqlite_schema.sql is native TEXT or NULL; an absent matching row remains false.
        try {
            /** @var string|false|null $sql */
            $sql = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        // Accept the actual frozen users definition, including its singleton constraint.
        // An advertised user_version or a CHECK hidden in a comment is insufficient.
        $expected = "CREATE TABLE users (id INTEGER PRIMARY KEY CHECK (id = 1), password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')))";
        $normalize = static fn (string $value): string => RegexReplacement::text(preg_replace('/\s+/', replacement: '', subject: $value));
        if (!is_string($sql) || $normalize($sql) !== $normalize($expected)) {
            throw new InstallationException('Installation schema incompatible.');
        }
        return self::validatedHash($db, 'SELECT id, password_hash FROM main.users');
    }

    private static function validatedHash(\PDO $db, string $sql): string
    {
        $statement = \Tablo\SqlStatement::checked($db->query($sql));
        try { $users = \Tablo\SqlRows::all($statement->fetchAll(), \Tablo\SqlUsersIdPasswordHashRow::checked(...)); }
        finally { $statement->closeCursor(); }
        if (count($users) !== 1) { throw new InstallationException('Administrator unavailable.'); }
        $user = $users[0];
        $hash = $user['password_hash'];
        // PHP 8 uses a string algorithm identifier, or NULL for an unrecognized hash.
        /** @var array{algo: ?string, ...} $information */
        $information = password_get_info($hash);
        if ($user['id'] !== 1 || $information['algo'] === null
            || (str_starts_with($hash, '$2') && preg_match('/^\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}$/D', $hash) !== 1)) {
            throw new InstallationException('Administrator unavailable.');
        }
        return $hash;
    }

}
