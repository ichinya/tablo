<?php
declare(strict_types=1);

namespace Tablo;

final class ServerMigrationState
{
    /** @param (\Closure(string, int): void)|null $barrier */
    public function __construct(private readonly \PDO $db, private readonly ?\Closure $barrier = null) {}
    /** @return array{format: int, logical_version: int, phase: string} */
    public function status(): array
    {
        $rows = ServerSchema::rows($this->db, 'SELECT format, logical_version, phase FROM tablo_database_state WHERE id = 1');
        if (count($rows) !== 1 || !is_string($rows[0]['phase'] ?? null)) { throw new DatabaseFailure('schema'); }
        return ['format' => DatabaseInteger::checked($rows[0]['format'] ?? null),
            'logical_version' => DatabaseInteger::checked($rows[0]['logical_version'] ?? null), 'phase' => $rows[0]['phase']];
    }

    /** @param array{plan: string, installation: string, verifier: string} $receipt */
    public function seed(string $table, #[\SensitiveParameter] array $receipt): void
    {
        server_migration_seed_apply($this->db, $table, $receipt);
    }

    public function phase(string $phase): void
    {
        if ($this->db->inTransaction() || WriteTransaction::owns($this->db)) { throw new \LogicException('Caller owns database transaction.'); }
        $this->db->exec('START TRANSACTION');
        try {
            ServerWriterAdmission::lock($this->db);
            ServerSchema::rows($this->db, 'UPDATE tablo_database_state SET phase = ? WHERE id = 1', [$phase]);
            $this->commit();
        } catch (\Throwable $error) { try { $this->db->exec('ROLLBACK'); } catch (\Throwable $discarded) { unset($discarded); } throw $error; }
    }

    public function publish(int $predecessor, int $target): void
    {
        if ($this->db->inTransaction() || WriteTransaction::owns($this->db)) { throw new \LogicException('Caller owns database transaction.'); }
        $this->point('before-publication', $target);
        $this->db->exec('START TRANSACTION');
        try {
            ServerWriterAdmission::lock($this->db);
            ServerSchema::rows($this->db, "UPDATE tablo_database_state SET logical_version = ?, layout_checksum = ?, phase = 'ready' WHERE id = 1 AND logical_version = ?", [$target, ServerSchema::checksum($this->db), $predecessor]);
            $this->commit();
        } catch (\Throwable $error) { try { $this->db->exec('ROLLBACK'); } catch (\Throwable $discarded) { unset($discarded); } throw $error; }
        $this->point('after-publication', $target);
        ServerSchema::admit($this->db);
    }

    public function point(string $phase, int $step): void { if ($this->barrier !== null) { ($this->barrier)($phase, $step); } }

    private function commit(): void
    {
        try { $this->db->exec('COMMIT'); } catch (\PDOException $error) { unset($error); throw new DatabaseFailure('uncertain'); }
    }

}

// Initialize only absent singleton rows in a recognized installation.


/** @param array{plan: string, installation: string, verifier: string} $receipt */
function server_migration_seed_apply(\PDO $db, string $table, #[\SensitiveParameter] array $receipt): void
{
    if ($table === 'tablo_database_state') { server_migration_seed_state($db, $receipt); return; }
    if (in_array($table, ['tablo_writer_guard', 'installation_settings', 'worker_runtime', 'self_update'], strict: true)) { server_migration_seed_singleton($db, $table); return; }
    if ($table === 'notification_settings' && ServerSchema::rows($db, 'SELECT id FROM notification_settings') === []) {
        ServerSchema::rows($db, 'INSERT INTO notification_settings (id, installation_id) VALUES (1, ?)', [$receipt['installation']]);
    }
}


/** @param array{plan: string, installation: string, verifier: string} $receipt */
function server_migration_seed_state(\PDO $db, #[\SensitiveParameter] array $receipt): void
{
    $rows = ServerSchema::rows($db, 'SELECT installation_id, key_verifier, logical_version, phase FROM tablo_database_state');
    if ($rows === []) {
        ServerSchema::rows($db, "INSERT INTO tablo_database_state (id, format, logical_version, layout_checksum, phase, installation_id, key_verifier) VALUES (1, 1, 0, ?, 'initializing', ?, ?)",
            [ServerSchema::checksum($db), $receipt['installation'], $receipt['verifier']]);
        return;
    }
    if ([count($rows), $rows[0]['installation_id'] ?? null, $rows[0]['key_verifier'] ?? null] !== [1, $receipt['installation'], $receipt['verifier']]
        || !in_array($rows[0]['phase'] ?? null, ['initializing', 'ready'], strict: true)) { throw new DatabaseFailure('schema'); }
}


function server_migration_seed_singleton(\PDO $db, string $table): void
{
    if (ServerSchema::rows($db, 'SELECT id FROM ' . $table) === []) { $db->exec('INSERT INTO ' . $table . ' (id) VALUES (1)'); }
}
