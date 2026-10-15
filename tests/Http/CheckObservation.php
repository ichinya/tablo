<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SqlColumn;

/**
 * Required current-schema projections for the HTTP check equivalence tests.
 * Each reader preserves nullable observations and all unselected row entries.
 * @phpstan-type Metrics array{online: ?int, deployed_version: ?string, deployed_commit: ?string, latest_release: ?string, latest_commit: ?string, open_issues: ?int, open_prs: ?int, last_error: ?string, ...}
 * @phpstan-type Budget array{online: ?int, latest_release: ?string, latest_commit: ?string, open_issues: ?int, open_prs: ?int, last_error: ?string, ...}
 * @phpstan-type Framing array{online: ?int, health_error_code: ?string, health_http_status: ?int, response_time_ms: ?int, deployed_version: ?string, deployed_commit: ?string, latest_commit: ?string, open_issues: ?int, open_prs: ?int, last_error: ?string, ...}
 */
final class CheckObservation
{
    /** @return Metrics */
    public static function metrics(#[\SensitiveParameter] array $row): array
    {
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['deployed_commit'] = SqlColumn::nullableString($row, 'deployed_commit');
        $row['latest_release'] = SqlColumn::nullableString($row, 'latest_release');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['open_issues'] = SqlColumn::nullableInteger($row, 'open_issues');
        $row['open_prs'] = SqlColumn::nullableInteger($row, 'open_prs');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        return $row;
    }

    /** @return Budget */
    public static function budget(#[\SensitiveParameter] array $row): array
    {
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['latest_release'] = SqlColumn::nullableString($row, 'latest_release');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['open_issues'] = SqlColumn::nullableInteger($row, 'open_issues');
        $row['open_prs'] = SqlColumn::nullableInteger($row, 'open_prs');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        return $row;
    }

    /** @return Framing */
    public static function framing(#[\SensitiveParameter] array $row): array
    {
        $row['online'] = SqlColumn::nullableInteger($row, 'online');
        $row['health_error_code'] = SqlColumn::nullableString($row, 'health_error_code');
        $row['health_http_status'] = SqlColumn::nullableInteger($row, 'health_http_status');
        $row['response_time_ms'] = SqlColumn::nullableInteger($row, 'response_time_ms');
        $row['deployed_version'] = SqlColumn::nullableString($row, 'deployed_version');
        $row['deployed_commit'] = SqlColumn::nullableString($row, 'deployed_commit');
        $row['latest_commit'] = SqlColumn::nullableString($row, 'latest_commit');
        $row['open_issues'] = SqlColumn::nullableInteger($row, 'open_issues');
        $row['open_prs'] = SqlColumn::nullableInteger($row, 'open_prs');
        $row['last_error'] = SqlColumn::nullableString($row, 'last_error');
        return $row;
    }
}
