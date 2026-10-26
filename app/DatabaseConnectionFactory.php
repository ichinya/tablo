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
        cold_transfer_coarse_factory(1);
        try {
            if (!$configuration->server() || ($mode === 'maintenance' && $configuration->role !== 'migrator')) { throw new DatabaseFailure('configuration'); }
            if (!in_array($mode, ['existing', 'maintenance', 'transfer-source'], strict: true)) { throw new DatabaseFailure('configuration'); }
            DatabaseTransferPreflight::sourceMode($configuration, $mode);
            cold_transfer_coarse_factory(2);
            $observation = ServerMetadataAdmission::observe($configuration, $mode === 'maintenance' ? null : 10);
            cold_transfer_coarse_factory(3);
            $session = null;
            try {
                $db = ServerPdoConnection::open($configuration);
                cold_transfer_coarse_factory(4);
                $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
                $session->metadataObservation = $observation;
                ServerMetadataAdmission::bind($db, $observation);
                cold_transfer_coarse_factory(5);
                if ($mode !== 'maintenance') {
                    ServerSchema::admit($db);
                    if (in_array($configuration->role, ['writer', 'migrator'], strict: true)) {
                        $vault ??= TokenVault::configured();
                        if ($vault === null || !$vault->isExternal()) { throw new DatabaseFailure('key'); }
                        ServerKeyIdentity::authenticate($db, $vault);
                        if ($mode === 'existing') { ExternalKeyAuthentication::authenticateExternalKey($db, $vault); }
                    }
                }
                cold_transfer_coarse_factory(6);
                if ($mode === 'transfer-source') { PdoMysqlOptions::stream($db); $db->exec('SET SESSION TRANSACTION READ ONLY'); }
                cold_transfer_coarse_factory(7);
                return $db;
            } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
            finally { if ($session !== null) { $session->metadataObservation = null; } }
        } finally { cold_transfer_coarse_factory(8); }
    }

}

/** Temporary dispatch never autoloads a diagnostic outside its exact bootstrap gate. */
function cold_transfer_coarse_factory(int $step): void
{
    if (class_exists(ColdTransferCoarsePeak::class, autoload: false)) { ColdTransferCoarsePeak::factory($step); }
}
