<?php
declare(strict_types=1);

namespace Tablo;

function authenticate_read_api(\PDO $db, #[\SensitiveParameter] string $secret): bool
{
    $stored = ReadApiTokenRepository::stored($db);
    $current = ReadApiTokenRepository::administrator($db);
    return $stored !== null && $current !== null && hash_equals($stored['administrator_fingerprint'], $current)
        && hash_equals($stored['secret_hash'], ReadApiToken::digest($secret));
}

/** @return array{present: bool, active: bool, created_at: ?string} */
function read_api_token_metadata(\PDO $db): array
{
    $stored = ReadApiTokenRepository::stored($db);
    $current = ReadApiTokenRepository::administrator($db);
    return ['present' => $stored !== null, 'active' => $stored !== null && $current !== null && hash_equals($stored['administrator_fingerprint'], $current),
        'created_at' => $stored['created_at'] ?? null];
}

/** @param \Closure(): bool $authorize */
function rotate_read_api_token(\PDO $db, #[\SensitiveParameter] string $fingerprint, \Closure $authorize): string
{
    $secret = ReadApiToken::generate();
    ReadApiTokenRepository::locked($db, $fingerprint, $authorize, static function () use ($db, $secret, $fingerprint): void {
        ReadApiTokenRepository::write($db, 'INSERT INTO main.read_api_token(id,secret_hash,administrator_fingerprint,created_at) VALUES(1,?,?,?)
            ON CONFLICT(id) DO UPDATE SET secret_hash=excluded.secret_hash,administrator_fingerprint=excluded.administrator_fingerprint,created_at=excluded.created_at',
            [ReadApiToken::digest($secret), $fingerprint, gmdate('Y-m-d\TH:i:s\Z')]);
    });
    return $secret;
}

/** @param \Closure(): bool $authorize */
function revoke_read_api_token(\PDO $db, #[\SensitiveParameter] string $fingerprint, \Closure $authorize): void
{
    ReadApiTokenRepository::locked($db, $fingerprint, $authorize, static function () use ($db): void {
        ReadApiTokenRepository::write($db, 'DELETE FROM main.read_api_token WHERE id=1');
    });
}

function read_api_administrator(\PDO $db): ?string
    {
        $statement = SqlStatement::checked($db->query('SELECT id, substr(CAST(password_hash AS BLOB),1,513) AS hash FROM main.users LIMIT 2'));
        try { $rows = SqlRows::all($statement->fetchAll(\PDO::FETCH_ASSOC), static fn (array $row): array => $row); }
        finally { $statement->closeCursor(); }
        if (count($rows) !== 1) { return null; }
        $row = $rows[0];
        /** @var mixed $hash Native bounded credential scalar. */
        $hash = $row['hash'] ?? null;
        if (($row['id'] ?? null) !== 1 || !is_string($hash) || strlen($hash) > 512) { return null; }
        /** @var array{algo: ?string, ...} $information PHP reports null for an unrecognized password algorithm. */
        $information = password_get_info($hash);
        if ($information['algo'] === null) { return null; }
        return AdministratorFingerprint::fingerprint($hash);
    }

/** @return array{secret_hash: string, administrator_fingerprint: string, created_at: string}|null */
function read_api_stored_token(\PDO $db): ?array
    {
        $statement = SqlStatement::checked($db->query("SELECT id,
            CASE WHEN typeof(secret_hash)='text' THEN substr(CAST(secret_hash AS BLOB),1,65) ELSE 0 END AS secret_hash,
            CASE WHEN typeof(administrator_fingerprint)='text' THEN substr(CAST(administrator_fingerprint AS BLOB),1,65) ELSE 0 END AS administrator_fingerprint,
            CASE WHEN typeof(created_at)='text' THEN substr(CAST(created_at AS BLOB),1,21) ELSE 0 END AS created_at FROM main.read_api_token LIMIT 2"));
        try { $rows = SqlRows::all($statement->fetchAll(\PDO::FETCH_ASSOC), static fn (array $row): array => $row); }
        finally { $statement->closeCursor(); }
        if ($rows === []) { return null; }
        $row = $rows[0];
        /** @var mixed $hash Native bounded digest scalar. */
        $hash = $row['secret_hash'] ?? null;
        /** @var mixed $fingerprint Native bounded credential binding scalar. */
        $fingerprint = $row['administrator_fingerprint'] ?? null;
        $created = ApiSiteDto::timestamp($row['created_at'] ?? null);
        if (count($rows) !== 1 || ($row['id'] ?? null) !== 1 || !ReadApiToken::validDigest($hash) || !ReadApiToken::validDigest($fingerprint)
            || !is_string($hash) || !is_string($fingerprint) || $created === null) { throw new \RuntimeException('Service unavailable.'); }
        return ['secret_hash' => $hash, 'administrator_fingerprint' => $fingerprint, 'created_at' => $created];
    }

/** @param \Closure(): bool $authorize
 * @param \Closure(): void $write */
function read_api_token_locked(\PDO $db, #[\SensitiveParameter] string $expected, \Closure $authorize, #[\SensitiveParameter] \Closure $write): void
    {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $current = ReadApiTokenRepository::administrator($db);
            if ($current === null || !ReadApiToken::validDigest($expected) || !hash_equals($current, $expected) || !$authorize()) {
                throw new \RuntimeException('Administrator authorization expired.');
            }
            $write();
            $db->exec('COMMIT');
        } catch (\Throwable) {
            try { $db->exec('ROLLBACK'); } catch (\Throwable $discardedCleanup) { unset($discardedCleanup); }
            throw new \RuntimeException('Cannot update read API token.');
        }
    }

/** @param list<string> $values */
function read_api_token_write(\PDO $db, string $sql, #[\SensitiveParameter] array $values = []): void
    {
        $statement = SqlStatement::checked($db->prepare($sql));
        try { $statement->execute($values); }
        finally { $statement->closeCursor(); }
    }

/** @return array{present: bool, active: bool, created_at: ?string} */
function read_api_settings_metadata(?ReadApiTokenRepository $tokens): array
{
    if ($tokens === null) { throw new \RuntimeException('Read API metadata unavailable.'); }
    return $tokens->metadata();
}

