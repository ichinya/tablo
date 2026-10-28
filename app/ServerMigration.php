<?php
declare(strict_types=1);

namespace Tablo;

final class ServerMigration
{
    /** @param (\Closure(string, int): void)|null $barrier */
    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $vault, private readonly ?\Closure $barrier = null) {}
    public function initialize(#[\SensitiveParameter] string $receiptPath): void
    {
        ServerMigrationLock::run($this->db, $this->vault, function (ServerLock $lock) use ($receiptPath): void {
            (new ServerBootstrap($this->db, $this->vault, $this->barrier))->run($receiptPath, $lock);
        });
    }
    public function migrate(): void
    {
        ServerMigrationLock::run($this->db, $this->vault, function (ServerLock $lock): void {
            $state = ServerKeyIdentity::forMigration($this->db, $this->vault);
            if ($state['version'] === 11) { ServerSchema::admit($this->db); return; }
            $predecessor = $state['version'];
            if (!in_array($predecessor, [9, 10], strict: true) || $state['checksum'] !== ServerSchema::checksum($this->db, $predecessor)
                || !in_array($state['phase'], ['ready', 'migrating'], strict: true)) { throw new DatabaseFailure('schema'); }
            $objects = ServerSchema::objects($this->db);
            $tables = ServerSchema::layout($this->db)['tables'];
            $added = server_migration_added($this->db, $state, $tables, $objects);
            $stateOwner = new ServerMigrationState($this->db, $this->barrier);
            $stateOwner->phase('migrating');
            $plan = hash('sha256', 'tablo:' . $predecessor . ':11:' . ServerSchema::checksum($this->db));
            $journal = new ServerMigrationJournal($this->db, $plan);
            server_migration_widen_state($this->db, $stateOwner, $journal, $predecessor);
            foreach ($added as $offset => $name) {
                $step = $offset + 1;
                $lock->assertOwned();
                $index = array_search($name, array_column($tables, 'name'), strict: true);
                $table = $index === false ? throw new DatabaseFailure('schema') : ($tables[$index] ?? throw new DatabaseFailure('schema'));
                $journal->intent($step, $table['checksum'], $predecessor, 11, $name);
                $stateOwner->point('before-ddl', $step);
                if (!in_array($name, $objects, strict: true)) { $this->db->exec($table['create']); }
                $stateOwner->point('after-ddl', $step); ServerSchema::validateTable($this->db, $table);
                if ($name === 'self_update') { $stateOwner->seed($name, ['plan' => $plan, 'installation' => '', 'verifier' => '']); }
                $journal->done($step);
            }
            ServerSchema::validateLayout($this->db);
            $stateOwner->publish($predecessor, target: 11);
        });
    }

    /** @return array{format: int, logical_version: int, phase: string} */
    public function status(): array { return (new ServerMigrationState($this->db))->status(); }

}

/** @param array{version: int, checksum: string, phase: string} $state
 * @param list<\Tablo\ServerSchema::Table> $tables
 * @param list<string> $objects
 * @return list<string> */
function server_migration_added(\PDO $db, array $state, array $tables, array $objects): array
{
    $added = $state['version'] === 9 ? ['read_api_token', 'self_update'] : ['self_update'];
    $existing = array_values(array_diff(array_column($tables, 'name'), $added));
    $actual = array_values(array_diff($objects, $added)); sort($actual, SORT_STRING); sort($existing, SORT_STRING);
    if ($actual !== $existing) { throw new DatabaseFailure('schema'); }
    foreach ($tables as $table) {
        if (!in_array($table['name'], $objects, strict: true)) { continue; }
        if (in_array($table['name'], $added, strict: true) && $state['phase'] !== 'migrating') { throw new DatabaseFailure('schema'); }
        if ($table['name'] === 'tablo_database_state' && $state['phase'] === 'migrating') {
            server_migration_state_shape($db, $table, $state['version']); continue;
        }
        ServerSchema::validateTable($db, ServerSchemaProjection::table($table, $state['version']));
    }
    return $added;
}

/** @param \Tablo\ServerSchema::Table $table */
function server_migration_state_shape(\PDO $db, array $table, int $version): bool
{
    try { ServerSchema::validateTable($db, $table); return true; }
    catch (DatabaseFailure $error) { if ($error->category !== 'schema') { throw $error; } }
    ServerSchema::validateTable($db, ServerSchemaProjection::table($table, $version));
    return false;
}

function server_migration_widen_state(\PDO $db, ServerMigrationState $state, ServerMigrationJournal $journal, int $predecessor): void
{
    $tables = ServerSchema::layout($db)['tables'];
    $table = $tables[1] ?? throw new DatabaseFailure('schema');
    if ($table['name'] !== 'tablo_database_state') { throw new DatabaseFailure('schema'); }
    $current = server_migration_state_shape($db, $table, $predecessor);
    $journal->widenStateIntent($predecessor, $table['checksum']);
    $state->point('before-ddl', 0);
    if (!$current) {
        $drop = DatabaseSession::get($db)?->configuration->engine === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $db->exec('ALTER TABLE `tablo_database_state` ' . $drop . ' `tablo_database_state_c3`, ADD CONSTRAINT `tablo_database_state_c3` CHECK (`logical_version` BETWEEN 0 AND 11)');
    }
    $state->point('after-ddl', 0); ServerSchema::validateTable($db, $table); $journal->done(0);
}
