<?php
declare(strict_types=1);

namespace Tablo;

final class ServerBootstrap
{
    /** @param (\Closure(string, int): void)|null $barrier */
    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault, private readonly ?\Closure $barrier = null) {}
    public function run(#[\SensitiveParameter] string $receiptPath, ServerLock $lock): void
    {
        $tables = ServerSchema::layout($this->db)['tables'];
        $objects = ServerSchema::objects($this->db);
        $receipt = (new ServerBootstrapReceipt($this->db, $this->vault))->read($receiptPath, count($objects));
        $prefix = array_slice(array_column($tables, 'name'), offset: 0, length: count($objects)); sort($prefix, SORT_STRING);
        if ($objects !== $prefix) { throw new DatabaseFailure('schema'); }
        $journal = new ServerMigrationJournal($this->db, $receipt['plan']);
        foreach ($tables as $index => $table) {
            if (!in_array($table['name'], $objects, strict: true)) { continue; }
            ServerSchema::validateTable($this->db, $table);
            if ($index > 0) { $journal->assertPlan(); }
        }
        $state = new ServerMigrationState($this->db, $this->barrier);
        $step = new ServerMigrationStep($this->db, $journal, $state);
        foreach ($tables as $index => $table) { $lock->assertOwned(); $step->apply($index, $table, $receipt); }
        ServerSchema::validateLayout($this->db);
        $state->publish(predecessor: 0, target: 10);
        ServerKeyIdentity::authenticate($this->db, $this->vault);
    }

}
