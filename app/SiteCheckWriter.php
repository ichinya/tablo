<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/**
 * @phpstan-type Config array{id: int, name: string, url: string, repository: string, branch: string, health_path: string, version_path: string,
 * version_json_path: string, health_check_mode: string, health_json_path: string, health_json_operator: string,
 * health_json_expected_value: string, comparison_mode: string, enabled: int, sort_order: int, config_revision: int, ...}
 */
final class SiteCheckWriter
{
    public function __construct(private readonly \PDO $db) {}

    /** @param Config $site */
    public function updateCheck(#[\SensitiveParameter] array $site, array $state): bool
    {
        return (new ServerSiteCheck($this->db, $this))->updateCheck($site, $state);
    }

    /** @param list<mixed> $values Native binding deliberately admits unsupported values to its sanitized failure boundary. */
    public function executeBound(string $sql, #[\SensitiveParameter] array $values): int
    {
        ServerWriterAdmission::borrow($this->db);
        $statement = \Tablo\SqlStatement::checked($this->db->prepare($sql));
        foreach (array_keys($values) as $index) {
            try {
                $statement->bindValue($index + 1, $values[$index] ?? null, SqlBindingType::forValue($values[$index] ?? null));
            } catch (\PDOException) {
                // An invalid binding can retain raw native arguments. Keep its typed
                // storage failure without retaining that frame or diagnostic.
                throw new \PDOException('Cannot bind site configuration.');
            }
        }
        // No credential-bearing array enters native execute's argument trace.
        // Preserve the first real SQL/commit failure and the authoritative transaction.
        $statement->execute();
        return $statement->rowCount();
    }

    public function creditCheck(int $id, int $revision, array $service): void
    {
        $sqlite = 'INSERT INTO worker_progress (site_id, config_revision)
            SELECT id, config_revision FROM sites WHERE id = ? AND config_revision = ? AND enabled = 1
            ON CONFLICT(site_id) DO UPDATE SET config_revision = excluded.config_revision,
            latest_release = 0, latest_commit = 0, open_issues = 0, open_prs = 0
            WHERE worker_progress.config_revision <> excluded.config_revision';
        $server = 'INSERT INTO worker_progress (site_id, config_revision) SELECT id, config_revision FROM sites WHERE id = ? AND config_revision = ? AND enabled = 1
            ON DUPLICATE KEY UPDATE latest_release = IF(worker_progress.config_revision <> ?, 0, worker_progress.latest_release), latest_commit = IF(worker_progress.config_revision <> ?, 0, worker_progress.latest_commit),
            open_issues = IF(worker_progress.config_revision <> ?, 0, worker_progress.open_issues), open_prs = IF(worker_progress.config_revision <> ?, 0, worker_progress.open_prs), config_revision = ?';
        $statement = \Tablo\SqlStatement::checked($this->db->prepare(ServerDialect::choose($this->db, $sqlite, $server)));
        try { $statement->execute(DatabaseSession::server($this->db) ? [$id, $revision, $revision, $revision, $revision, $revision, $revision] : [$id, $revision]); }
        finally { $statement->closeCursor(); }
        foreach (WorkerStateRepository::FIELDS as $field) {
            if (!($service[$field] ?? false)) { continue; }
            $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT fairness_turn FROM worker_runtime WHERE id = 1'));
            try { $turn = (int) $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($turn >= 1_000_000_000) {
                $this->db->exec(ServerDialect::choose($this->db, 'UPDATE worker_progress SET latest_release = latest_release / 2,
                    latest_commit = latest_commit / 2, open_issues = open_issues / 2, open_prs = open_prs / 2',
                    'UPDATE worker_progress SET latest_release = latest_release DIV 2, latest_commit = latest_commit DIV 2, open_issues = open_issues DIV 2, open_prs = open_prs DIV 2'));
                $this->db->exec(ServerDialect::choose($this->db, 'UPDATE worker_runtime SET fairness_turn = fairness_turn / 2 WHERE id = 1',
                    'UPDATE worker_runtime SET fairness_turn = fairness_turn DIV 2 WHERE id = 1'));
            }
            $this->db->exec('UPDATE worker_runtime SET fairness_turn = fairness_turn + 1 WHERE id = 1');
            $statement = \Tablo\SqlStatement::checked($this->db->prepare('UPDATE worker_progress SET ' . $field . ' = (SELECT fairness_turn FROM worker_runtime WHERE id = 1)
                WHERE site_id = ? AND config_revision = ? AND EXISTS(SELECT 1 FROM sites
                WHERE id = ? AND config_revision = ? AND enabled = 1)'));
            try { $statement->execute([$id, $revision, $id, $revision]); }
            finally { $statement->closeCursor(); }
        }
    }


}
