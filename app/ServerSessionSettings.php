<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class ServerSessionSettings
{
    public static function configure(PDO $db): void
    {
        $db->exec("SET SESSION time_zone = '+00:00'");
        $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION,NO_AUTO_VALUE_ON_ZERO'");
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $db->exec('SET SESSION lock_wait_timeout = 2');
        $db->exec('SET NAMES utf8mb4 COLLATE ' . DatabaseSession::collation($db));
        if (DatabaseSession::get($db)?->configuration->engine === 'mariadb') { $db->exec('SET SESSION check_constraint_checks = ON'); }
        $statement = SqlStatement::checked($db->query('SELECT @@session.time_zone AS zone, @@session.sql_mode AS mode, @@session.character_set_connection AS charset, @@session.collation_connection AS collation, @@session.default_storage_engine AS engine'));
        try { $settings = SqlRows::required($statement->fetch(PDO::FETCH_ASSOC), static fn (array $row): array => $row); } finally { $statement->closeCursor(); }
        $observed = [SqlColumn::string($settings, 'zone'), SqlColumn::string($settings, 'charset'), SqlColumn::string($settings, 'collation'), strtolower(SqlColumn::string($settings, 'engine'))];
        if ($observed !== ['+00:00', 'utf8mb4', DatabaseSession::collation($db), 'innodb']) { throw new DatabaseFailure('engine'); }
        $modes = explode(',', SqlColumn::string($settings, 'mode'));
        if (array_diff(['STRICT_ALL_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'NO_AUTO_VALUE_ON_ZERO'], $modes) !== []
            || array_intersect(['ANSI_QUOTES', 'PAD_CHAR_TO_FULL_LENGTH'], $modes) !== []) { throw new DatabaseFailure('engine'); }
        foreach ([PDO::ATTR_EMULATE_PREPARES, PDO::ATTR_STRINGIFY_FETCHES] as $attribute) {
            if (!in_array($db->getAttribute($attribute), [false, 0], strict: true)) { throw new DatabaseFailure('engine'); }
        }
    }
}
