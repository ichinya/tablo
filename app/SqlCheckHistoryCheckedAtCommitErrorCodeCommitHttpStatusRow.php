<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Record array{"checked_at": string, "commit_error_code": null|string, "commit_http_status": null|int, "config_revision": int, "deployed_commit": null|string, "deployed_version": null|string, "health_error_code": null|string, "health_http_status": null|int, "id": int, "latest_commit": null|string, "latest_release": null|string, "online": null|int, "release_error_code": null|string, "release_http_status": null|int, "response_time_ms": null|int, "site_id": int, "version_error_code": null|string, "version_http_status": null|int, "version_status": string, ...} */
final class SqlCheckHistoryCheckedAtCommitErrorCodeCommitHttpStatusRow
{
    /** @return Record */
    public static function checked(#[\SensitiveParameter] array $row): array
    {
        $row['checked_at'] = SqlColumn::string($row, 'checked_at');
        $row['commit_error_code'] = SqlColumn::nullableString($row, 'commit_error_code');
        $row['commit_http_status'] = SqlColumn::nullableInteger($row, 'commit_http_status');
        $row['config_revision'] = SqlColumn::integer($row, 'config_revision');
        $row['deployed_commit'] = SqlColumn::nullableString($row, 'deployed_commit');
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['id'] = SqlColumn::integer($row, 'id');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['latest_release'] = SqlColumn::nullableString($row, 'latest_release');
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['release_error_code'] = SqlColumn::nullableString($row, 'release_error_code');
        $row['release_http_status'] = SqlColumn::nullableInteger($row, 'release_http_status');
        $row['response_time_ms'] = SqlColumn::nullableInteger($row, 'response_time_ms');
        $row['site_id'] = SqlColumn::integer($row, 'site_id');
        $row['version_error_code'] = SqlColumn::nullableString($row, 'version_error_code');
        $row['version_http_status'] = SqlColumn::nullableInteger($row, 'version_http_status');
        $row['version_status'] = SqlColumn::string($row, 'version_status');
        return $row;
    }
}
