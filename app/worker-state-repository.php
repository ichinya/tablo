<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class WorkerStateRepository
{
    public const FIELDS = ['latest_release', 'latest_commit', 'open_issues', 'open_prs'];

    public function __construct(private readonly PDO $db, #[\SensitiveParameter] private ?SiteRepository $sites = null)
    {
        $sites?->assertConnection($db); // Refuse before key selection or any transaction/write.
    }

    // Only call after acquiring the real lifetime installation lock.
    public function begin(): string
    {
        $generation = bin2hex(random_bytes(16));
        $statement = $this->db->prepare('UPDATE worker_runtime SET run_id = ?, stop_requested = 0 WHERE id = 1');
        $statement->execute([$generation]);
        if ($statement->rowCount() !== 1) { throw new \RuntimeException('Invalid worker control state.'); }
        return $generation;
    }

    public function generation(): ?string
    {
        $statement = $this->db->query('SELECT run_id FROM worker_runtime WHERE id = 1');
        try { return $statement->fetchColumn() ?: null; }
        finally { $statement->closeCursor(); }
    }

    public function requestStop(string $observed_generation): bool
    {
        $statement = $this->db->prepare('UPDATE worker_runtime SET stop_requested = 1 WHERE id = 1 AND run_id = ?');
        $statement->execute([$observed_generation]);
        return $statement->rowCount() === 1;
    }

    public function stopping(string $generation): bool
    {
        $statement = $this->db->prepare('SELECT stop_requested FROM worker_runtime WHERE id = 1 AND run_id = ?');
        $statement->execute([$generation]);
        try { $value = $statement->fetchColumn(); }
        finally { $statement->closeCursor(); }
        return $value === false || (int) $value === 1;
    }

    public function finish(string $generation): void
    {
        $this->db->prepare('UPDATE worker_runtime SET run_id = NULL, stop_requested = 0 WHERE id = 1 AND run_id = ?')->execute([$generation]);
    }

    public function turns(int $id, int $revision): array
    {
        $statement = $this->db->prepare('SELECT * FROM worker_progress WHERE site_id = ? AND config_revision = ?');
        $statement->execute([$id, $revision]);
        try { $row = $statement->fetch(); }
        finally { $statement->closeCursor(); }
        return array_intersect_key($row ?: array_fill_keys(self::FIELDS, 0), array_flip(self::FIELDS));
    }

    // SiteRepository owns the shared post-network result/history/credit transaction.
    public function settle(#[\SensitiveParameter] array $site, array $result): bool
    {
        // Preserve external-key pass lifetime and actual history/incident/credit transaction owner.
        $this->sites ??= new SiteRepository($this->db);
        $this->sites->assertWorkerKeyAvailable();
        return $this->sites->settleWorkerCheck($site, $result);
    }
}
