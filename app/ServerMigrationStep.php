<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Table from ServerSchema */
final class ServerMigrationStep
{
    public function __construct(private readonly \PDO $db, private readonly ServerMigrationJournal $journal, private readonly ServerMigrationState $state) {}
    /**
     * @param Table $table
     * @param array{plan: string, installation: string, verifier: string} $receipt
     */
    public function apply(int $index, array $table, #[\SensitiveParameter] array $receipt): void
    {
        if ($index !== 0) { $this->journal->intent($index, $table['checksum'], predecessor: 0, target: 10, table: $table['name']); }
        try {
            $this->state->point('before-ddl', $index);
            if (!in_array($table['name'], ServerSchema::objects($this->db), strict: true)) { $this->db->exec($table['create']); }
            $this->state->point('after-ddl', $index);
            ServerSchema::validateTable($this->db, $table);
            if ($index === 0) { $this->journal->intent($index, $table['checksum'], predecessor: 0, target: 10, table: $table['name']); }
            $this->state->seed($table['name'], $receipt);
            $this->journal->done($index);
            $this->state->point('after-journal', $index);
        } catch (\Throwable $error) {
            try { $this->journal->failed($index, $error instanceof DatabaseFailure ? $error->category : 'storage'); } catch (\Throwable $discarded) { unset($discarded); }
            throw $error;
        }
    }

}
