<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class ServerSessionAdmission
{
    public static function pinReader(PDO $db): void
    {
        foreach (['SELECT id, password_hash FROM users LIMIT 0',
            'SELECT id, secret_hash, administrator_fingerprint, created_at FROM read_api_token LIMIT 0',
            'SELECT ' . ReadApiRepository::projectionFor($db) . ' FROM sites LIMIT 0',
            'SELECT id, format, logical_version, layout_checksum, phase, installation_id, committed_history_high_watermark FROM tablo_database_state LIMIT 0'] as $sql) {
            $statement = SqlStatement::checked($db->query($sql)); $statement->closeCursor();
        }
        ServerSchemaState::ready($db);
    }

    public static function attest(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): void
    {
        $statement = SqlStatement::checked($db->query('SELECT VERSION() AS version, @@version_comment AS comment, DATABASE() AS catalog'));
        try { $row = SqlRows::required($statement->fetch(PDO::FETCH_ASSOC), static fn (array $value): array => $value); } finally { $statement->closeCursor(); }
        if (SqlColumn::string($row, 'catalog') !== $configuration->catalog) { throw new DatabaseFailure('engine'); }
        $version = SqlColumn::string($row, 'version');
        $maria = stripos($version . ' ' . SqlColumn::string($row, 'comment'), needle: 'mariadb') !== false;
        $pattern = $maria ? '/\A11\.4\.[0-9]+(?:-|\z)/D' : '/\A8\.4\.[0-9]+(?:-|\z)/D';
        if (($configuration->engine === 'mariadb') !== $maria || preg_match($pattern, $version) !== 1) { throw new DatabaseFailure('engine'); }
    }
    public static function tls(PDO $db, #[\SensitiveParameter] DatabaseConfiguration $configuration): void
    {
        if ($configuration->tls === 'verify') {
            $statement = SqlStatement::checked($db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'"));
            try { $cipher = SqlRows::required($statement->fetch(PDO::FETCH_NUM), static fn (array $value): array => $value); } finally { $statement->closeCursor(); }
            if (SqlColumn::string(['cipher' => $cipher[1] ?? null], 'cipher') === '') { throw new DatabaseFailure('unavailable'); }
        }
    }
}
