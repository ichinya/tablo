<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-type CheckResult array{
 *   online: ?int, deployed_version: ?string, deployed_commit: ?string, latest_release: ?string, latest_commit: ?string,
 *   open_issues: ?int, open_prs: ?int, health_error_code: ?string, health_http_status: ?int, version_status: string,
 *   version_error_code: ?string, version_http_status: ?int, release_error_code: ?string, release_http_status: ?int,
 *   commit_error_code: ?string, commit_http_status: ?int, response_time_ms: ?int, last_error: ?string, checked_at: string,
 *   version_outcome: array{status: string, reason: string, http_status: ?int},
 *   git_outcomes: array<string, array{status: string, reason: string, http_status: ?int, eligible_at: ?int}>,
 *   worker_service?: array<string, bool>
 * }
 */
final class CheckState
{
    /** @return CheckResult */
    public static function initial(#[\SensitiveParameter] array $site): array
    {
        return ['online' => null, 'deployed_version' => null, 'deployed_commit' => null,
            'latest_release' => null, 'latest_commit' => null, 'open_issues' => null, 'open_prs' => null,
            'health_error_code' => null, 'health_http_status' => null,
            'version_status' => ($site['version_path'] ?? '') === '' ? 'skipped' : 'error',
            'version_error_code' => null, 'version_http_status' => null,
            'release_error_code' => 'check-error', 'release_http_status' => null,
            'commit_error_code' => 'check-error', 'commit_http_status' => null,
            'response_time_ms' => null, 'last_error' => null, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version_outcome' => ['status' => 'skipped', 'reason' => 'not-configured', 'http_status' => null],
            'git_outcomes' => []];
    }

}
