<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Native transport only; callers must perform their explicit admission before using data. */
final class ServerPdoConnection
{
    public static function open(#[\SensitiveParameter] DatabaseConfiguration $configuration): PDO
    {
        try {
            $db = new PDO($configuration->dsn(), $configuration->user, $configuration->password, PdoMysqlOptions::forConfiguration($configuration));
            DatabaseSession::register($db, $configuration);
            ServerSessionAdmission::attest($db, $configuration);
            ServerSessionSettings::configure($db);
            ServerSessionAdmission::tls($db, $configuration);
            return $db;
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
    }
}
