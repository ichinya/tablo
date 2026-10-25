<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Native transport only; callers must perform their explicit admission before using data. */
final class ServerPdoConnection
{
    public static function open(#[\SensitiveParameter] DatabaseConfiguration $configuration): PDO
    {
        server_pdo_connection_phase(phase: 130);
        try {
            $db = new PDO($configuration->dsn(), $configuration->user, $configuration->password, PdoMysqlOptions::forConfiguration($configuration));
            server_pdo_connection_phase(phase: 131);
            DatabaseSession::register($db, $configuration);
            ServerSessionAdmission::attest($db, $configuration);
            ServerSessionSettings::configure($db);
            ServerSessionAdmission::tls($db, $configuration);
            server_pdo_connection_phase(phase: 132);
            return $db;
        } catch (\PDOException $error) { server_pdo_connection_phase(phase: 901); throw DatabaseFailure::fromNative($error); }
    }
}

/** Observer-only forwarding never autoloads the transfer facade or observer host. */
function server_pdo_connection_phase(int $phase): void
{
    if (function_exists('Tablo\\database_transfer_phase_mark')) { database_transfer_phase_mark($phase); }
}
