<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type SiteRecord array{
 *   id: int, name: string, url: string, provider: string, repository: string, branch: string,
 *   health_path: string, version_path: string, version_json_path: string, health_check_mode: string,
 *   health_json_path: string, health_json_operator: string, health_json_expected_value: string,
 *   comparison_mode: string, enabled: int, sort_order: int, config_revision: int,
 *   github_token: ?string, endpoint_token: ?string, git_token_id: ?int, selected_token_snapshot: ?string,
 *   online: ?int, deployed_version: ?string, deployed_commit: ?string, latest_release: ?string, latest_commit: ?string,
 *   open_issues: ?int, open_prs: ?int, response_time_ms: ?int, last_error: ?string, checked_at: ?string,
 *   health_error_code: ?string, health_http_status: ?int, created_at: string, updated_at: string, ...
 * }
 */
final class SiteRow
{
    /** @return SiteRecord */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        return [...$row,
            'id' => SqlColumn::integer($row, 'id'), 'name' => SqlColumn::string($row, 'name'),
            'url' => SqlColumn::string($row, 'url'), 'provider' => SqlColumn::string($row, 'provider'),
            'repository' => SqlColumn::string($row, 'repository'), 'branch' => SqlColumn::string($row, 'branch'),
            'health_path' => SqlColumn::string($row, 'health_path'), 'version_path' => SqlColumn::string($row, 'version_path'),
            'version_json_path' => SqlColumn::string($row, 'version_json_path'), 'health_check_mode' => SqlColumn::string($row, 'health_check_mode'),
            'health_json_path' => SqlColumn::string($row, 'health_json_path'), 'health_json_operator' => SqlColumn::string($row, 'health_json_operator'),
            'health_json_expected_value' => SqlColumn::string($row, 'health_json_expected_value'), 'comparison_mode' => SqlColumn::string($row, 'comparison_mode'),
            'enabled' => SqlColumn::integer($row, 'enabled'), 'sort_order' => SqlColumn::integer($row, 'sort_order'),
            'config_revision' => SqlColumn::integer($row, 'config_revision'), 'github_token' => SqlColumn::nullableString($row, 'github_token'),
            'endpoint_token' => SqlColumn::nullableString($row, 'endpoint_token'), 'git_token_id' => SqlColumn::nullableInteger($row, 'git_token_id'),
            'selected_token_snapshot' => SqlColumn::nullableString($row, 'selected_token_snapshot'), 'online' => SqlColumn::nullableInteger($row, 'online'),
            'deployed_version' => SqlColumn::nullableString($row, 'deployed_version'), 'deployed_commit' => SqlColumn::nullableString($row, 'deployed_commit'),
            'latest_release' => SqlColumn::nullableString($row, 'latest_release'), 'latest_commit' => SqlColumn::nullableString($row, 'latest_commit'),
            'open_issues' => SqlColumn::nullableInteger($row, 'open_issues'), 'open_prs' => SqlColumn::nullableInteger($row, 'open_prs'),
            'response_time_ms' => SqlColumn::nullableInteger($row, 'response_time_ms'), 'last_error' => SqlColumn::nullableString($row, 'last_error'),
            'checked_at' => SqlColumn::nullableString($row, 'checked_at'), 'health_error_code' => SqlColumn::nullableString($row, 'health_error_code'),
            'health_http_status' => SqlColumn::nullableInteger($row, 'health_http_status'), 'created_at' => SqlColumn::string($row, 'created_at'),
            'updated_at' => SqlColumn::string($row, 'updated_at')];
    }

    /** @return list<SiteRecord> */
    public static function rows(#[\SensitiveParameter] array $rows): array
    {
        $decoded = [];
        foreach (array_keys($rows) as $key) {
            if (!is_array($rows[$key] ?? null)) { throw new \RuntimeException('Invalid database row.'); }
            $decoded[] = self::checked($rows[$key]);
        }
        return $decoded;
    }
}
