<?php
declare(strict_types=1);

namespace Tablo;

final class ServerKeyIdentity
{
    public static function authenticate(\PDO $db, #[\SensitiveParameter] TokenVault $vault): void
    {
        $session = DatabaseSession::get($db);
        if ($session === null || $session->installationId === '' || !$vault->isExternal()) { throw new DatabaseFailure('key'); }
        $statement = SqlStatement::checked($db->query('SELECT key_verifier FROM tablo_database_state WHERE id = 1'));
        try {
            /** @var string|null|false $verifier Native private verifier or no row. */
            $verifier = $statement->fetchColumn();
        } finally { $statement->closeCursor(); }
        if (!is_string($verifier) || !hash_equals($verifier, $vault->serverVerifier($session->installationId))) { throw new DatabaseFailure('key'); }
        $session->keyAuthenticated = true;
        $session->vault = $vault;
    }

    /** @return array{version: int, checksum: string, phase: string} */
    public static function forMigration(\PDO $db, #[\SensitiveParameter] TokenVault $vault): array
    {
        $rows = ServerSchema::rows($db, 'SELECT id, format, logical_version, layout_checksum, phase, installation_id FROM tablo_database_state');
        if (count($rows) !== 1) { throw new DatabaseFailure('schema'); }
        $state = $rows[0];
        if ([DatabaseInteger::checked($state['id'] ?? null), DatabaseInteger::checked($state['format'] ?? null)] !== [1, 1]) { throw new DatabaseFailure('schema'); }
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        $session->installationId = SqlColumn::string($state, 'installation_id');
        self::authenticate($db, $vault);
        return ['version' => DatabaseInteger::checked($state['logical_version'] ?? null), 'checksum' => SqlColumn::string($state, 'layout_checksum'), 'phase' => SqlColumn::string($state, 'phase')];
    }
}
