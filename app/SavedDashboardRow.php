<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type Row array{id: int, name: string, url: string, repository: string, branch: string,
 * enabled: int, sort_order: int, online: ?int, checked_at: ?string, deployed_version: ?string,
 * latest_release: ?string, deployed_commit: ?string, latest_commit: ?string, comparison_mode: string,
 * version_path: string, last_error: ?string, health_error_code: ?string, health_http_status: ?int,
 * open_issues: ?int, open_prs: ?int, response_time_ms: ?int}
 */
final class SavedDashboardRow
{
    /** @return Row */
    public static function checked(array $row): array
    {
        return [
            'id' => SqlColumn::integer($row, 'id'), 'name' => SqlColumn::string($row, 'name'),
            'url' => SqlColumn::string($row, 'url'), 'repository' => SqlColumn::string($row, 'repository'),
            'branch' => SqlColumn::string($row, 'branch'), 'enabled' => SqlColumn::integer($row, 'enabled'),
            'sort_order' => SqlColumn::integer($row, 'sort_order'), 'online' => SqlColumn::nullableInteger($row, 'online'),
            'checked_at' => SqlColumn::nullableString($row, 'checked_at'),
            'deployed_version' => SqlColumn::nullableString($row, 'deployed_version'),
            'latest_release' => SqlColumn::nullableString($row, 'latest_release'),
            'deployed_commit' => SqlColumn::nullableString($row, 'deployed_commit'),
            'latest_commit' => SqlColumn::nullableString($row, 'latest_commit'),
            'comparison_mode' => SqlColumn::string($row, 'comparison_mode'),
            'version_path' => SqlColumn::string($row, 'version_path'),
            'last_error' => SqlColumn::nullableString($row, 'last_error'),
            'health_error_code' => SqlColumn::nullableString($row, 'health_error_code'),
            'health_http_status' => SqlColumn::nullableInteger($row, 'health_http_status'),
            'open_issues' => SqlColumn::nullableInteger($row, 'open_issues'),
            'open_prs' => SqlColumn::nullableInteger($row, 'open_prs'),
            'response_time_ms' => SqlColumn::nullableInteger($row, 'response_time_ms'),
        ];
    }
}
