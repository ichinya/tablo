<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-import-type Header from DatabaseTransferManifest */
final class DatabaseTransferTarget
{
    public static function plan(string $digest): string
    {
        return database_transfer_target_plan($digest);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function prepare(PDO $db, array $header, string $digest, #[\SensitiveParameter] TokenVault $vault): void
    {
        database_transfer_target_prepare($db, $header, $digest, $vault);
    }

    /**
     * @template T
     *
 * @param \Closure(): T $operation
     *
 * @return T
     */
    public static function atomic(PDO $db, \Closure $operation): mixed
    {
        return database_transfer_persistence_atomic($db, $operation);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function publish(PDO $db, array $header): void
    {
        database_transfer_target_publish($db, $header);
    }
}

// Internal operations for this fixed transfer module.

function database_transfer_target_plan(string $digest): string
{ return hash('sha256', "tablo/import/v1\0" . $digest); }


/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_target_prepare(PDO $db, array $header, string $digest, #[\SensitiveParameter] TokenVault $vault): void
{
    ServerSchema::validateLayout($db);
    $state = ServerKeyIdentity::forMigration($db, $vault);
    $plans = ServerSchema::rows($db, 'SELECT DISTINCT plan FROM tablo_migration_journal WHERE predecessor = 11 AND target = 11');
    if ($plans !== []) {
        if (array_column($plans, 'plan') !== [database_transfer_target_plan($digest)] || !in_array($state['phase'], ['importing', 'ready'], strict: true)
            || DatabaseSession::get($db)?->installationId !== $header['installation']) { throw new DatabaseFailure('schema'); }
        return;
    }
    if ($state['version'] !== 11 || $state['phase'] !== 'ready') { throw new DatabaseFailure('schema'); }
    database_transfer_persistence_atomic($db, static function () use ($db, $header, $digest): void {
        database_transfer_target_empty($db, $header);
        $first = $header['tables'][0] ?? throw new DatabaseFailure('schema');
        (new ServerMigrationJournal($db, database_transfer_target_plan($digest)))->intent(0, $first['sha256'], 11, 11, 'users');
        foreach (['installation_settings', 'worker_runtime', 'notification_settings', 'self_update'] as $table) { $db->exec('DELETE FROM ' . $table); }
        ServerSchema::rows($db, "UPDATE tablo_database_state SET phase = 'importing', installation_id = ?, key_verifier = ?, committed_history_high_watermark = ? WHERE id = 1",
            [$header['installation'], $header['verifier'], $header['watermark']]);
    });
    $session = DatabaseSession::get($db) ?? throw new DatabaseFailure('schema');
    $session->installationId = $header['installation'];
}


/** @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_target_empty(PDO $db, array $header): void
{
    $installation = DatabaseSession::get($db)->installationId ?? throw new DatabaseFailure('schema');
    $seeds = ['self_update' => [1, 1, 360, 0, '', null, 'no_data', null, null, null, null, null, 0, 0, null, 0], 'installation_settings' => [1, 10, 30], 'worker_runtime' => [1, null, 0, 0],
        'notification_settings' => [1, $installation, 0, 0, 0, 0, null, null, 1, 1, 1, 0, 0]];
    foreach (DatabaseTransferManifest::iterate($header['columns']) as $table) {
        $expected = array_key_exists($table['name'], $seeds) ? [$seeds[$table['name']]] : [];
        if (DatabaseTransferSequence::scalar($db, 'SELECT COUNT(*) AS value FROM `' . $table['name'] . '`') !== count($expected)) { throw new DatabaseFailure('schema'); }
        $rows = iterator_to_array(DatabaseTransferRows::read($db, $table));
        if ($rows !== $expected) { throw new DatabaseFailure('schema'); }
        unset($table);
    }
}


/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_target_publish(PDO $db, array $header): void
{
    database_transfer_persistence_atomic($db, static function () use ($db, $header): void {
        ServerSchema::rows($db, "UPDATE tablo_database_state SET logical_version = 11, layout_checksum = ?, phase = 'ready', committed_history_high_watermark = ? WHERE id = 1 AND phase = 'importing'",
            [ServerSchema::checksum($db), $header['watermark']]);
    });
    ServerSchema::admit($db);
}




/**
 * @template T
 *
 * @param \Closure(): T $operation
 *
 * @return T
 */
function database_transfer_persistence_atomic(PDO $db, \Closure $operation): mixed
{
    if ($db->inTransaction() || WriteTransaction::owns($db)) { throw new \LogicException('Caller owns database transaction.'); }
    $db->exec('START TRANSACTION');
    try {
        ServerWriterAdmission::lock($db);
        $value = $operation();
        try { $db->exec('COMMIT'); } catch (\PDOException $commit) { unset($commit); throw new DatabaseFailure('uncertain'); }
        return $value;
    } catch (\Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (\Throwable $discarded) { unset($discarded); }
        if ($error instanceof \PDOException) { throw DatabaseFailure::fromNative($error); }
        throw $error;
    }
}
