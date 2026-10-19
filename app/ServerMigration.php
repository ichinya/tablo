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
            if ($state['version'] === 10) { ServerSchema::admit($this->db); return; }
            if ([$state['version'], $state['checksum']] !== [9, ServerSchema::checksum($this->db, version: 9)]
                || !in_array($state['phase'], ['ready', 'migrating'], strict: true)) { throw new DatabaseFailure('schema'); }
            $objects = ServerSchema::objects($this->db);
            $apiPresent = in_array('read_api_token', $objects, strict: true);
            if (!$apiPresent) { ServerSchema::validateLayout($this->db, version: 9); }
            if ($apiPresent && $state['phase'] !== 'migrating') { throw new DatabaseFailure('schema'); }
            $stateOwner = new ServerMigrationState($this->db, $this->barrier);
            $stateOwner->phase('migrating');
            $tables = ServerSchema::layout($this->db)['tables'];
            $table = $tables[17] ?? throw new DatabaseFailure('schema');
            if ($table['name'] !== 'read_api_token') { throw new DatabaseFailure('schema'); }
            $plan = hash('sha256', 'tablo:9:10:' . ServerSchema::checksum($this->db));
            $journal = new ServerMigrationJournal($this->db, $plan);
            $journal->intent(0, $table['checksum'], 9, 10, $table['name']);
            $stateOwner->point('before-ddl', 0);
            if (!in_array('read_api_token', $objects, strict: true)) { $this->db->exec($table['create']); }
            $stateOwner->point('after-ddl', 0); ServerSchema::validateLayout($this->db); $journal->done(0);
            $stateOwner->publish(predecessor: 9, target: 10);
        });
    }

    /** @return array{format: int, logical_version: int, phase: string} */
    public function status(): array { return (new ServerMigrationState($this->db))->status(); }

}
