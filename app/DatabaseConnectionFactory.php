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
        $options = PdoMysqlOptions::forConfiguration($configuration);
        try {
            $db = new PDO($configuration->dsn(), $configuration->user, $configuration->password, $options);
            DatabaseSession::register($db, $configuration);
            ServerSessionAdmission::attest($db, $configuration);
            ServerSessionSettings::configure($db);
            ServerSessionAdmission::tls($db, $configuration);
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
    }

}
