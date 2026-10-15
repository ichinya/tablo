<?php
declare(strict_types=1);

namespace Tablo;

final class VaultLocation
{
    public static function forDatabase(\PDO $db): TokenVault
    {
        $external = TokenVault::configured();
        if ($external !== null) { return $external; }
        $databasePath = \Tablo\SqlRows::one(\Tablo\SqlStatement::checked($db->query('PRAGMA database_list'))->fetch(), \Tablo\SqlFileNameSeqRow::checked(...))['file'] ?? '';
        $directory = $databasePath !== '' ? dirname($databasePath) : dirname(__DIR__) . '/storage';
        // Keep the existing key filename so installed credentials remain readable.
        return new TokenVault($directory . '/github-token.key');
    }
}
