<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class DatabaseTransferSequence
{
    /**
 * @return array{sites: int, git_tokens: int, check_history: int} */
    public static function floors(PDO $db): array
    {
        return database_transfer_sequence_floors($db);
    }

    public static function watermark(PDO $db): int
    {
        return database_transfer_sequence_watermark($db);
    }

    /**
 * @param array{sites: int, git_tokens: int, check_history: int} $floors */
    public static function apply(PDO $db, array $floors, int $watermark): void
    {
        database_transfer_sequence_apply($db, $floors, $watermark);
    }

    public static function scalar(PDO $db, string $sql): int
    {
        return database_transfer_sequence_scalar($db, $sql);
    }
}

// Internal operations for this fixed transfer module.

/**
 * @return array{sites: int, git_tokens: int, check_history: int} */
function database_transfer_sequence_floors(PDO $db): array
{
    $floors = [];
    foreach (['sites', 'git_tokens', 'check_history'] as $table) {
        $maximum = database_transfer_sequence_scalar($db, 'SELECT COALESCE(MAX(id),0) AS value FROM ' . $table);
        $allocated = database_transfer_persistence_allocated($db, $table);
        $floor = max($maximum, $allocated);
        if ($floor < 0 || $floor === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        $floors[$table] = $floor;
    }
    return ['sites' => $floors['sites'], 'git_tokens' => $floors['git_tokens'], 'check_history' => $floors['check_history']];
}


function database_transfer_sequence_watermark(PDO $db): int
{
    $values = [database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(id),0) AS value FROM check_history'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(id),0) AS value FROM incidents'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(last_history_id),0) AS value FROM incident_checkpoints'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(last_history_id),0) AS value FROM notification_checkpoints'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(source_id),0) AS value FROM notification_slots'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(activation_id),0) AS value FROM notification_settings'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(recovery_history_id),0) AS value FROM incidents'),
        database_transfer_sequence_scalar($db, sql: 'SELECT COALESCE(MAX(interruption_history_id),0) AS value FROM incidents')];
    if (DatabaseSession::server($db)) {
        $committed = database_transfer_sequence_scalar($db, sql: 'SELECT committed_history_high_watermark AS value FROM tablo_database_state WHERE id = 1');
        if ($committed < max($values) || $committed === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        return $committed;
    }
    $values[] = database_transfer_persistence_allocated($db, table: 'check_history');
    $maximum = max($values);
    if ($maximum < 0 || $maximum === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
    return $maximum;
}


/**
 * @param array{sites: int, git_tokens: int, check_history: int} $floors */
function database_transfer_sequence_apply(PDO $db, array $floors, int $watermark): void
{
    $floors['check_history'] = max($floors['check_history'], $watermark);
    foreach ($floors as $table => $floor) {
        if ($floor < 0 || $floor === PHP_INT_MAX) { throw new DatabaseFailure('schema'); }
        if (DatabaseSession::server($db)) { $db->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . ($floor + 1)); continue; }
        ServerSchema::rows($db, 'DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
        ServerSchema::rows($db, 'INSERT INTO sqlite_sequence (name,seq) VALUES (?,?)', [$table, $floor]);
    }
}


function database_transfer_sequence_scalar(PDO $db, string $sql): int
{
    $rows = ServerSchema::rows($db, $sql);
    return DatabaseInteger::checked($rows[0]['value'] ?? null);
}


function database_transfer_persistence_allocated(PDO $db, string $table): int
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $rows = ServerSchema::rows($db, 'SELECT seq AS value FROM sqlite_sequence WHERE name = ?', [$table]);
        return $rows === [] ? 0 : DatabaseInteger::checked($rows[0]['value'] ?? null);
    }
    // MySQL caches INFORMATION_SCHEMA table statistics for24 hours by default.
    // An offline transfer must preserve allocations beyond MAX(id), including gaps.
    if (DatabaseSession::get($db)?->configuration->engine === 'mysql') { $db->exec('SET SESSION information_schema_stats_expiry = 0'); }
    $rows = ServerSchema::rows($db, 'SELECT AUTO_INCREMENT AS value FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    $next = DatabaseInteger::checked($rows[0]['value'] ?? null);
    if ($next < 1) { throw new DatabaseFailure('schema'); }
    return $next - 1;
}
