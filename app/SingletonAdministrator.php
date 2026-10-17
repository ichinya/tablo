<?php
declare(strict_types=1);

namespace Tablo;

final class SingletonAdministrator
{
    public static function hash(\PDO $db): string
    {
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
        $statement = \Tablo\SqlStatement::checked($db->query('SELECT id, password_hash FROM main.users'));
        try { $users = \Tablo\SqlRows::all($statement->fetchAll(), \Tablo\SqlUsersIdPasswordHashRow::checked(...)); }
        finally { $statement->closeCursor(); }
        if (count($users) !== 1) { throw new InstallationException('Administrator unavailable.'); }
        $user = $users[0];
        $hash = $user['password_hash'];
        $algorithm = PasswordHashPolicy::storedAlgorithm($hash);
        if (in_array($algorithm, ['argon2id', 'argon2i'], strict: true) && !PasswordService::supportsStoredHash($hash)) {
            throw new PasswordRuntimeFailure();
        }
        // Only bounded policy-recognized bcrypt may lack native metadata (2a/2b).
        // Keep the original bytes for credential fingerprints and conditional writes.
        /** @var array{algo: ?string, ...} $information */
        $information = password_get_info($hash);
        if ($user['id'] !== 1 || $algorithm === null
            || ($algorithm !== '2y' && $information['algo'] === null)) {
            throw new InstallationException('Administrator unavailable.');
        }
        return $hash;
    }

}
