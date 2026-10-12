<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"branch": string, "checked_at": null|string, "comparison_mode": string, "config_revision": int, "created_at": string, "deployed_commit": null|string, "deployed_version": null|string, "enabled": int, "endpoint_token": null|string, "git_token_id": null|int, "github_token": null|string, "health_check_mode": string, "health_error_code": null|string, "health_http_status": null|int, "health_json_expected_value": string, "health_json_operator": string, "health_json_path": string, "health_path": string, "id": int, "last_error": null|string, "latest_commit": null|string, "latest_release": null|string, "name": string, "online": null|int, "open_issues": null|int, "open_prs": null|int, "provider": string, "repository": string, "response_time_ms": null|int, "sort_order": int, "updated_at": string, "url": string, "version_json_path": string, "version_path": string, ...} */
final class SqlSitesBranchCheckedAtComparisonModeRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['branch'] = SqlColumn::string($row, 'branch');
        $row['checked_at'] = SqlColumn::nullableString($row, 'checked_at');
        $row['comparison_mode'] = SqlColumn::string($row, 'comparison_mode');
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['created_at'] = SqlColumn::string($row, 'created_at');
        $row['deployed_commit'] = SqlColumn::nullableString($row, 'deployed_commit');
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['enabled'] = SqlColumn::integer($row, 'enabled');
        $row['endpoint_token'] = SqlColumn::nullableString($row, 'endpoint_token');
        $row['git_token_id'] = SqlColumn::nullableInteger($row, 'git_token_id');
        $row['github_token'] = SqlColumn::nullableString($row, 'github_token');
        $row['health_check_mode'] = SqlColumn::string($row, 'health_check_mode');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['health_json_expected_value'] = SqlColumn::string($row, 'health_json_expected_value');
        $row['health_json_operator'] = SqlColumn::string($row, 'health_json_operator');
        $row['health_json_path'] = SqlColumn::string($row, 'health_json_path');
        $row['health_path'] = SqlColumn::string($row, 'health_path');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['latest_release'] = SqlColumn::nullableString($row, 'latest_release');
        $row['name'] = SqlColumn::string($row, 'name');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['open_issues'] = SqlColumn::nullableInteger($row, 'open_issues');
        $row['open_prs'] = SqlColumn::nullableInteger($row, 'open_prs');
        $row['provider'] = SqlColumn::string($row, 'provider');
        $row['repository'] = SqlColumn::string($row, 'repository');
        $row['response_time_ms'] = SqlColumn::nullableInteger($row, 'response_time_ms');
        $row['sort_order'] = SqlColumn::integer($row, 'sort_order');
        $row['updated_at'] = SqlColumn::string($row, 'updated_at');
        $row['url'] = SqlColumn::string($row, 'url');
        $row['version_json_path'] = SqlColumn::string($row, 'version_json_path');
        $row['version_path'] = SqlColumn::string($row, 'version_path');
        return $row;
    }
}
