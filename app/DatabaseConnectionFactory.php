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
        database_connection_factory_phase(phase: 120);
        if (!$configuration->server() || ($mode === 'maintenance' && $configuration->role !== 'migrator')) { throw new DatabaseFailure('configuration'); }
        if (!in_array($mode, ['existing', 'maintenance', 'transfer-source'], strict: true)) { throw new DatabaseFailure('configuration'); }
        DatabaseTransferPreflight::sourceMode($configuration, $mode);
        database_connection_factory_phase(phase: 121);
        $observation = ServerMetadataAdmission::observe($configuration, $mode === 'maintenance' ? null : 10);
        database_connection_factory_phase(phase: 122);
        $session = null;
        try {
            $db = ServerPdoConnection::open($configuration);
            database_connection_factory_phase(phase: 123);
            $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
            $session->metadataObservation = $observation;
            ServerMetadataAdmission::bind($db, $observation);
            database_connection_factory_phase(phase: 124);
            if ($mode !== 'maintenance') {
                ServerSchema::admit($db);
                database_connection_factory_phase(phase: 125);
                if (in_array($configuration->role, ['writer', 'migrator'], strict: true)) {
                    $vault ??= TokenVault::configured();
                    if ($vault === null || !$vault->isExternal()) { throw new DatabaseFailure('key'); }
                    ServerKeyIdentity::authenticate($db, $vault);
                    if ($mode === 'existing') { ExternalKeyAuthentication::authenticateExternalKey($db, $vault); }
                    database_connection_factory_phase(phase: 126);
                }
            }
            if ($mode === 'transfer-source') { PdoMysqlOptions::stream($db); $db->exec('SET SESSION TRANSACTION READ ONLY'); database_connection_factory_phase(phase: 127); }
            return $db;
        } catch (\PDOException $error) { database_connection_factory_phase(phase: 901); throw DatabaseFailure::fromNative($error); }
        finally { if ($session !== null) { $session->metadataObservation = null; } database_connection_factory_phase(phase: 128); }
    }

}

/** Observer-only forwarding never autoloads the transfer facade or observer host. */
function database_connection_factory_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
