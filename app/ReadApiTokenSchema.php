<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-token-schema.php';

final class ReadApiTokenSchema
{
    public static function sql(): string { return read_api_token_schema(); }
    public static function migrate(\PDO $db): void { $db->exec(self::sql()); }
}
