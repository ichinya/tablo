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
        // A result for an old configuration must not overwrite a concurrent edit.
        $fields = ['online', 'health_error_code', 'health_http_status', 'deployed_version', 'deployed_commit', 'latest_release', 'latest_commit',
            'open_issues', 'open_prs', 'response_time_ms', 'last_error', 'checked_at'];
        $config = ['name', 'url', 'repository', 'branch', 'health_path', 'version_path', 'version_json_path',
            'health_check_mode', 'health_json_path', 'health_json_operator', 'health_json_expected_value',
            'comparison_mode', 'enabled', 'sort_order', 'config_revision'];
        $sql = 'UPDATE sites SET ' . implode(', ', array_map(static fn ($f) => "{$f} = ?", $fields))
            . ' WHERE id = ? AND enabled = 1 AND ' . implode(' AND ', array_map(static fn ($f) => "{$f} = ?", $config)) . ' AND github_token IS ?
                AND endpoint_token IS ? AND git_token_id IS ? AND (SELECT encrypted_token FROM git_tokens WHERE id = sites.git_token_id) IS ?';
        return $this->executeBound($sql, [...array_map(static fn ($f) => $state[$f] ?? null, $fields), $site['id'], ...array_map(static fn ($f) => $site[$f] ?? throw new \LogicException('Missing site configuration.'), $config),
            $site['github_token'] ?? null, $site['endpoint_token'] ?? null, $site['git_token_id'] ?? null, $site['selected_token_snapshot'] ?? null]) === 1;
    }

    /** @param list<mixed> $values Native binding deliberately admits unsupported values to its sanitized failure boundary. */
    public function executeBound(string $sql, #[\SensitiveParameter] array $values): int
    {
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
        $statement = \Tablo\SqlStatement::checked($this->db->prepare('INSERT INTO worker_progress (site_id, config_revision)
            SELECT id, config_revision FROM sites WHERE id = ? AND config_revision = ? AND enabled = 1
            ON CONFLICT(site_id) DO UPDATE SET config_revision = excluded.config_revision,
            latest_release = 0, latest_commit = 0, open_issues = 0, open_prs = 0
            WHERE worker_progress.config_revision <> excluded.config_revision'));
        try { $statement->execute([$id, $revision]); }
        finally { $statement->closeCursor(); }
        foreach (WorkerStateRepository::FIELDS as $field) {
            if (!($service[$field] ?? false)) { continue; }
            $statement = \Tablo\SqlStatement::checked($this->db->query('SELECT fairness_turn FROM worker_runtime WHERE id = 1'));
            try { $turn = (int) $statement->fetchColumn(); }
            finally { $statement->closeCursor(); }
            if ($turn >= 1_000_000_000) {
                $this->db->exec('UPDATE worker_progress SET latest_release = latest_release / 2,
                    latest_commit = latest_commit / 2, open_issues = open_issues / 2, open_prs = open_prs / 2');
                $this->db->exec('UPDATE worker_runtime SET fairness_turn = fairness_turn / 2 WHERE id = 1');
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
