<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Table from ServerSchema
 * @phpstan-import-type Column from ServerSchema
 * @phpstan-import-type Layout from ServerSchema
 */
final class ServerSchemaState
{
    public static function ready(PDO $db): void
    {
        $rows = ServerSchema::rows($db, 'SELECT id, format, logical_version, layout_checksum, phase, installation_id, committed_history_high_watermark FROM tablo_database_state');
        if (count($rows) !== 1) { self::refuse(); }
        $row = $rows[0];
        $observed = [DatabaseInteger::checked($row['id'] ?? null), DatabaseInteger::checked($row['format'] ?? null),
            DatabaseInteger::checked($row['logical_version'] ?? null), SqlColumn::string($row, 'layout_checksum'), SqlColumn::string($row, 'phase')];
        if ($observed !== [1, 1, 10, ServerSchema::checksum($db), 'ready']) { self::refuse(); }
        $installation = SqlColumn::string($row, 'installation_id');
        if (preg_match('/\A[0-9a-f]{32}\z/D', $installation) !== 1 || DatabaseInteger::checked($row['committed_history_high_watermark'] ?? null) < 0) { self::refuse(); }
        $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
        if ($session->installationId !== '' && $session->installationId !== $installation) { self::refuse(); }
        $session->installationId = $installation;
    }
    private static function refuse(): never { throw new DatabaseFailure('schema'); }

}
