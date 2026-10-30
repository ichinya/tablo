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
            $options = PdoMysqlOptions::forConfiguration($configuration);
            // mysqlnd captures this at construction; 1 selects direct reads without a retained PHP read buffer.
            // Restore the process setting before admission while the PDO keeps its captured transport policy.
            $buffer = ini_get('mysqlnd.net_read_buffer_size');
            $previous = $buffer === false || $buffer === '1' ? false : RuntimeIni::set('mysqlnd.net_read_buffer_size', '1');
            try { $db = new PDO($configuration->dsn(), $configuration->user, $configuration->password, $options); }
            finally {
                $restored = $previous === false || RuntimeIni::set('mysqlnd.net_read_buffer_size', $previous) !== false;
            }
            if (!$restored) { throw new DatabaseFailure('unavailable'); }
            DatabaseSession::register($db, $configuration);
            ServerSessionAdmission::attest($db, $configuration);
            ServerSessionSettings::configure($db);
            ServerSessionAdmission::tls($db, $configuration);
            return $db;
        } catch (\PDOException $error) { throw DatabaseFailure::fromNative($error); }
    }
}
