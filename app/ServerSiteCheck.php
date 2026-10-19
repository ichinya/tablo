<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Config from SiteCheckWriter */
final class ServerSiteCheck
{
    public function __construct(private readonly \PDO $db, private readonly SiteCheckWriter $writes) {}
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
        if (DatabaseSession::server($this->db)) {
            $sql = 'UPDATE sites SET ' . implode(', ', array_map(static fn (string $field): string => $field . ' = ?', $fields))
                . ' WHERE id = ? AND enabled = 1 AND ' . implode(' AND ', array_map(static fn (string $field): string =>
                    in_array($field, ['enabled', 'sort_order', 'config_revision'], strict: true) ? $field . ' <=> ?' : 'CAST(' . $field . ' AS BINARY) <=> CAST(? AS BINARY)', $config))
                . ' AND github_token <=> CAST(? AS BINARY) AND endpoint_token <=> CAST(? AS BINARY) AND git_token_id <=> ?'
                . ' AND (SELECT encrypted_token FROM git_tokens WHERE id = sites.git_token_id) <=> CAST(? AS BINARY)';
        }
        return $this->writes->executeBound($sql, [...array_map(static fn ($f) => $state[$f] ?? null, $fields), $site['id'], ...array_map(static fn ($f) => $site[$f] ?? throw new \LogicException('Missing site configuration.'), $config),
            $site['github_token'] ?? null, $site['endpoint_token'] ?? null, $site['git_token_id'] ?? null, $site['selected_token_snapshot'] ?? null]) === 1;
    }
}
