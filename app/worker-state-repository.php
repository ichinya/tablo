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

    // One post-network transaction owns both the guarded current result and service credit.
    public function settle(#[\SensitiveParameter] array $site, array $result): bool
    {
        // Reuse the pass lifetime; control-only construction must stay key-independent.
        $this->sites ??= new SiteRepository($this->db);
        $this->sites->assertWorkerKeyAvailable();
        // BEGIN outside the catch: a failed/nested BEGIN never rolls back caller-owned work.
        $this->db->beginTransaction();
        try {
            if (!$site['enabled'] || !$this->sites->storeCheck($site, $result)) {
                $this->db->rollBack();
                return false;
            }
            $this->credit($site['id'], $site['config_revision'], $result['worker_service']);
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            try { if ($this->db->inTransaction()) { $this->db->rollBack(); } }
            catch (\Throwable) { /* Preserve the original settlement failure. */ }
            throw $error;
        }
    }

    private function credit(int $id, int $revision, array $service): void
    {
            $this->db->prepare('INSERT INTO worker_progress (site_id, config_revision)
                SELECT id, config_revision FROM sites WHERE id = ? AND config_revision = ? AND enabled = 1
                ON CONFLICT(site_id) DO UPDATE SET config_revision = excluded.config_revision,
                latest_release = 0, latest_commit = 0, open_issues = 0, open_prs = 0
                WHERE worker_progress.config_revision <> excluded.config_revision')->execute([$id, $revision]);
            foreach (self::FIELDS as $field) {
                if (!($service[$field] ?? false)) { continue; }
                // Bounded counters: rare order-preserving scale-down keeps old debt ahead.
                $turn = (int) $this->db->query('SELECT fairness_turn FROM worker_runtime WHERE id = 1')->fetchColumn();
                if ($turn >= 1000000000) {
                    $this->db->exec('UPDATE worker_progress SET latest_release = latest_release / 2,
                        latest_commit = latest_commit / 2, open_issues = open_issues / 2, open_prs = open_prs / 2');
                    $this->db->exec('UPDATE worker_runtime SET fairness_turn = fairness_turn / 2 WHERE id = 1');
                }
                $this->db->exec('UPDATE worker_runtime SET fairness_turn = fairness_turn + 1 WHERE id = 1');
                $this->db->prepare('UPDATE worker_progress SET ' . $field . ' = (SELECT fairness_turn FROM worker_runtime WHERE id = 1)
                    WHERE site_id = ? AND config_revision = ? AND EXISTS(SELECT 1 FROM sites
                    WHERE id = ? AND config_revision = ? AND enabled = 1)')->execute([$id, $revision, $id, $revision]);
            }
    }
}
