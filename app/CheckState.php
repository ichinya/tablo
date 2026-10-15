<?php
declare(strict_types=1);

namespace Tablo;

final class CheckState
{
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
