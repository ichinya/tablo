<?php
declare(strict_types=1);

namespace Tablo;

/** Committed DML records surround individual implicit-commit DDL operations. */
final class ServerMigrationJournal
{
    public function __construct(private readonly \PDO $db, private readonly string $plan) {}

    public function intent(int $step, string $checksum, int $predecessor, int $target, string $table): void
    {
        $rows = ServerSchema::rows($this->db, 'SELECT checksum, predecessor, target, before_predicate, after_predicate, phase FROM tablo_migration_journal WHERE plan = ? AND step = ?', [$this->plan, $step]);
        if ($rows !== []) {
            if (count($rows) !== 1) { throw new DatabaseFailure('schema'); }
            $row = $rows[0];
            $observed = [SqlColumn::string($row, 'checksum'), DatabaseInteger::checked($row['predecessor'] ?? null), DatabaseInteger::checked($row['target'] ?? null),
                SqlColumn::string($row, 'before_predicate'), SqlColumn::string($row, 'after_predicate')];
            if ($observed !== [$checksum, $predecessor, $target, 'absent:' . $table, 'exact:' . $table]
                || !in_array(SqlColumn::string($row, 'phase'), ['intent', 'done', 'failed'], strict: true)) { throw new DatabaseFailure('schema'); }
            return;
        }
        ServerSchema::rows($this->db, 'INSERT INTO tablo_migration_journal (plan, step, checksum, predecessor, target, before_predicate, after_predicate, phase) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->plan, $step, $checksum, $predecessor, $target, 'absent:' . $table, 'exact:' . $table, 'intent']);
    }

    public function done(int $step): void
    {
        ServerSchema::rows($this->db, "UPDATE tablo_migration_journal SET phase = 'done', failure_category = NULL WHERE plan = ? AND step = ?", [$this->plan, $step]);
    }

    public function failed(int $step, string $category): void
    {
        ServerSchema::rows($this->db, "UPDATE tablo_migration_journal SET phase = 'failed', failure_category = ? WHERE plan = ? AND step = ?", [$category, $this->plan, $step]);
    }
    public function assertPlan(): void
    {
        $rows = ServerSchema::rows($this->db, 'SELECT DISTINCT plan FROM tablo_migration_journal');
        if (count($rows) !== 1 || ($rows[0]['plan'] ?? null) !== $this->plan) { throw new DatabaseFailure('schema'); }
    }

}
