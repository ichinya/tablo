<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

/** Only metadata necessary for these reads; never migrate or run foreign_key_check. */
final class ReadApiSchemaAdmission
{
    public static function authentication(\PDO $db): void
    { read_api_authentication_shape($db); }

    public static function sites(\PDO $db): void
    { read_api_sites_shape($db); }

    /** @param array<string, string> $required */
    public static function table(\PDO $db, string $table, array $required): void
    { read_api_table_shape($db, $table, $required); }
}
