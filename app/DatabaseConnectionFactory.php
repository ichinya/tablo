<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class DatabaseConnectionFactory
{
    public static function selected(#[\SensitiveParameter] DatabaseConfiguration $configuration, #[\SensitiveParameter] ?string $path, #[\SensitiveParameter] ?TokenVault $vault = null): PDO
    {
        if ($path !== null) { throw new DatabaseFailure('configuration'); }
        return self::open($configuration, vault: $vault);
    }
    public static function open(#[\SensitiveParameter] DatabaseConfiguration $configuration, string $mode = 'existing', #[\SensitiveParameter] ?TokenVault $vault = null): PDO
    {
        if (!$configuration->server() || ($mode === 'maintenance' && $configuration->role !== 'migrator')) { throw new DatabaseFailure('configuration'); }
        if (!in_array($mode, ['existing', 'maintenance', 'transfer-source'], strict: true)) { throw new DatabaseFailure('configuration'); }
        DatabaseTransferPreflight::sourceMode($configuration, $mode);
        $observation = ServerMetadataAdmission::observe($configuration, $mode === 'maintenance' ? null : 11);
        $session = null;
        try {
            $db = ServerPdoConnection::open($configuration);
            $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
            $session->metadataObservation = $observation;
            ServerMetadataAdmission::bind($db, $observation);
            if ($mode !== 'maintenance') {
                ServerSchema::admit($db);
                if (in_array($configuration->role, ['writer', 'migrator'], strict: true)) {
                    $vault ??= TokenVault::configured();
                    if ($vault === null || !$vault->isExternal()) { throw new DatabaseFailure('key'); }
                    ServerKeyIdentity::authenticate($db, $vault);
                    if ($mode === 'existing') { ExternalKeyAuthentication::authenticateExternalKey($db, $vault); }
                }
            }
            if ($mode === 'transfer-source') { PdoMysqlOptions::stream($db); $db->exec('SET SESSION TRANSACTION READ ONLY'); }
            return $db;
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
        finally { if ($session !== null) { $session->metadataObservation = null; } }
    }

}
