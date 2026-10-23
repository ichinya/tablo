<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 *
 * @phpstan-import-type Header from DatabaseTransferManifest
 *
 * @phpstan-import-type Table from DatabaseTransferManifest
 *
 * @phpstan-import-type Receipt from DatabaseTransferArchive
 *
 * @phpstan-import-type Values from DatabaseTransferRows
 */
final class DatabaseTransferImport
{
    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function server(PDO $db, #[\SensitiveParameter] string $directory, array $header, string $digest, #[\SensitiveParameter] TokenVault $vault): void
    {
        database_transfer_import_server($db, $directory, $header, $digest, $vault);
    }

    /**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
    public static function sqlite(PDO $scratch, array $header): void
    {
        database_transfer_sqlite_target_sqlite($scratch, $header);
    }
}

// Internal operations for this fixed transfer module.

/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_import_server(PDO $db, #[\SensitiveParameter] string $directory, array $header, string $digest, #[\SensitiveParameter] TokenVault $vault): void
{
    ServerMigrationLock::run($db, $vault, static function (ServerLock $lock) use ($db, $directory, $header, $digest, $vault): void {
        DatabaseTransferTarget::prepare($db, $header, $digest, $vault);
        if ((new ServerMigrationState($db))->status()['phase'] === 'ready') {
            DatabaseTransferVerification::database($db, $header, $vault);
            return;
        }
        $plan = DatabaseTransferTarget::plan($digest);
        foreach (DatabaseTransferManifest::tables() as $index => $table) {
            $lock->assertOwned();
            database_transfer_cursor_table($db, $directory, $table, $header['tables'][$index] ?? throw new DatabaseFailure('schema'), ['plan' => $plan, 'step' => $index]);
        }
        DatabaseTransferSequence::apply($db, $header['sequences'], $header['watermark']);
        DatabaseTransferVerification::database($db, $header, $vault);
        DatabaseTransferTarget::publish($db, $header);
    });
}

/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferRows::Values $values
 * @return \Tablo\DatabaseTransferRows::Values */
function database_transfer_value_primary(array $table, #[\SensitiveParameter] array $values): array
{
    $row = array_combine(array_column(DatabaseTransferManifest::columns($table), 'name'), $values);
    return array_map(static fn (string $name): int|string|null => $row[$name] ?? null, $table['primary']);
}


/**
 * @param \Tablo\DatabaseTransferManifest::Header $header */
function database_transfer_sqlite_target_sqlite(PDO $scratch, array $header): void
{
    DatabaseTransferSequence::apply($scratch, $header['sequences'], $header['watermark']);
    $scratch->exec('PRAGMA main.user_version = 10');
    if (ServerSchema::rows($scratch, 'PRAGMA foreign_key_check') !== []) { throw new DatabaseFailure('schema'); }
}


/**
 * @param \Tablo\DatabaseTransferManifest::Table $table
 * @param \Tablo\DatabaseTransferArchive::Receipt $receipt
 * @param array{plan: string, step: int} $position */
function database_transfer_cursor_table(PDO $db, #[\SensitiveParameter] string $directory, array $table, array $receipt, array $position): void
{
    $plan = $position['plan']; $step = $position['step'];
    $journal = new ServerMigrationJournal($db, $plan);
    $journal->intent($step, $receipt['sha256'], 10, 10, $table['name']);
    $records = ServerSchema::rows($db, 'SELECT `cursor` FROM tablo_migration_journal WHERE plan = ? AND step = ?', [$plan, $step]);
    $cursor = SqlColumn::nullableString($records[0] ?? [], 'cursor');
    $record = $cursor === null ? ['rows' => 0, 'primary' => []] : JsonRecord::decode($cursor);
    $previous = DatabaseInteger::checked($record['rows'] ?? null);
    $primaryCursor = JsonRecord::checked($record['primary'] ?? null);
    if ($previous < 0 || ($previous === 0 && $primaryCursor !== [])) { throw new DatabaseFailure('schema'); }
    if (DatabaseTransferSequence::scalar($db, 'SELECT COUNT(*) AS value FROM `' . $table['name'] . '`') !== $previous) { throw new DatabaseFailure('schema'); }
    $stored = DatabaseTransferRows::read($db, $table);
    $rowNumber = 0;
    foreach (DatabaseTransferArchive::read($directory, $table, $receipt) as $values) {
        $rowNumber++;
        if ($rowNumber <= $previous) {
            if ($stored->current() !== $values) { throw new DatabaseFailure('schema'); }
            if ($rowNumber === $previous && DatabaseTransferRows::encode(database_transfer_value_primary($table, $values)) !== $primaryCursor) { throw new DatabaseFailure('schema'); }
            $stored->next(); continue;
        }
        $primary = database_transfer_value_primary($table, $values);
        DatabaseTransferTarget::atomic($db, static function () use ($db, $table, $values, $plan, $step, $rowNumber, $primary): void {
            DatabaseTransferRows::insert($db, $table, $values);
            ServerSchema::rows($db, 'UPDATE tablo_migration_journal SET `cursor` = ? WHERE plan = ? AND step = ?',
                [JsonEncoding::encode(['rows' => $rowNumber, 'primary' => DatabaseTransferRows::encode($primary)]), $plan, $step]);
        });
    }
    if ($rowNumber < $previous) { throw new DatabaseFailure('schema'); }
    unset($values, $stored);
    DatabaseTransferVerification::table($db, $table, $receipt);
    $journal->done($step);
}
