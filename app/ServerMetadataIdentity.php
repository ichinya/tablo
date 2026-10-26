<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** Public server/catalog identity, never credential or routine/trigger bodies. */
final class ServerMetadataIdentity
{
    /** @return array<string, int|string> */
    public static function server(PDO $db): array
    {
        $uid = DatabaseSession::get($db)?->configuration->engine === 'mariadb' ? '@@server_uid' : '@@server_uuid';
        $row = ServerSchema::rows($db, 'SELECT DATABASE() AS catalog, VERSION() AS version, @@version_comment AS comment, @@hostname AS host, @@port AS port, @@server_id AS server_id, ' . $uid . ' AS server_uid')[0] ?? throw new DatabaseFailure('schema');
        $identity = [];
        foreach (['catalog', 'version', 'comment', 'host', 'server_uid'] as $name) { $identity[$name] = SqlColumn::string($row, $name); }
        foreach (['port', 'server_id'] as $name) { $identity[$name] = SqlColumn::integer($row, $name); }
        return $identity;
    }

    /** @param list<string> $names
     * @return list<array> */
    public static function installation(PDO $db, array $names): array
    {
        if (!in_array('tablo_database_state', $names, strict: true)) { return []; }
        return ServerSchema::rows($db, 'SELECT id,format,logical_version,layout_checksum,phase,installation_id FROM tablo_database_state ORDER BY id LIMIT 2');
    }

    public static function grantee(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): string
    {
        $row = ServerSchema::rows($db, 'SELECT CURRENT_USER() AS identity')[0] ?? throw new DatabaseFailure('schema');
        $identity = SqlColumn::string($row, 'identity'); $prefix = $configuration->user . '@';
        if (!str_starts_with($identity, $prefix)) { throw new DatabaseFailure('schema'); }
        $host = substr($identity, strlen($prefix));
        if (preg_match('/\A[a-zA-Z0-9_.:%\/+\-]{1,255}\z/D', $host) !== 1) { throw new DatabaseFailure('schema'); }
        return "'" . $configuration->user . "'@'" . $host . "'";
    }
}
