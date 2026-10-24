<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Config from SiteCheckWriter */
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
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) { return WriteTransaction::run($this->db, $this->begin(...)); }
        $generation = bin2hex(random_bytes(16));
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('UPDATE worker_runtime SET run_id = ?, stop_requested = 0 WHERE id = 1'));
        $statement->execute([$generation]);
        if ($statement->rowCount() !== 1) { throw new \RuntimeException('Invalid worker control state.'); }
        return $generation;
    }

    public function generation(): ?string
    {
        $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT run_id FROM worker_runtime WHERE id = 1'));
        try {
            /** @var string|null|false $generation */
            $generation = $statement->fetchColumn();
            return $generation ? $generation : null;
        }
        finally { $statement->closeCursor(); }
    }

    public function requestStop(string $observed_generation): bool
    {
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) { return WriteTransaction::run($this->db, fn (): bool => $this->requestStop($observed_generation), purpose: 'control'); }
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('UPDATE worker_runtime SET stop_requested = 1 WHERE id = 1 AND run_id = ?'));
        $statement->execute([$observed_generation]);
        return $statement->rowCount() === 1;
    }

    public function stopping(string $generation): bool
    {
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT stop_requested FROM worker_runtime WHERE id = 1 AND run_id = ?'));
        $statement->execute([$generation]);
        try {
            /** @var int|false $value */
            $value = $statement->fetchColumn();
        }
        finally { $statement->closeCursor(); }
        return $value === false || $value === 1;
    }

    public function finish(string $generation): void
    {
        if (DatabaseSession::server($this->db) && !WriteTransaction::owns($this->db)) { WriteTransaction::run($this->db, function () use ($generation): void { $this->finish($generation); }); return; }
        \Tablo\SqlStatement::checked($this->db->prepare('UPDATE worker_runtime SET run_id = NULL, stop_requested = 0 WHERE id = 1 AND run_id = ?'))->execute([$generation]);
    }

    /** @return array{latest_release: int, latest_commit: int, open_issues: int, open_prs: int} */
    public function turns(int $id, int $revision): array
    {
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('SELECT * FROM worker_progress WHERE site_id = ? AND config_revision = ?'));
        $statement->execute([$id, $revision]);
        try { $row = \Tablo\SqlRows::one($statement->fetch(), \Tablo\SqlWorkerProgressRow::checked(...)); }
        finally { $statement->closeCursor(); }
        if ($row === false) { return ['latest_release' => 0, 'latest_commit' => 0, 'open_issues' => 0, 'open_prs' => 0]; }
        return ['latest_release' => $row['latest_release'], 'latest_commit' => $row['latest_commit'],
            'open_issues' => $row['open_issues'], 'open_prs' => $row['open_prs']];
    }

    // SiteRepository owns the shared post-network result/history/credit transaction.
    /** @param Config $site */
    public function settle(#[\SensitiveParameter] array $site, array $result): bool
    {
        // Preserve external-key pass lifetime and actual history/incident/credit transaction owner.
        $this->sites ??= new SiteRepository($this->db);
        $this->sites->assertWorkerKeyAvailable();
        return $this->sites->settleWorkerCheck($site, $result);
    }
}
