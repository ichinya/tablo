<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryGitProjection
{
    public static function latest(array $state): array
    {
        return ['latest_release' => HistoryVersionText::version($state['latest_release'] ?? null),
            'latest_commit' => HistoryVersionText::commit($state['latest_commit'] ?? null)];
    }
    public static function diagnostics(array $state): array
    {
        return ['release_error_code' => HistoryScalar::code($state['release_error_code'] ?? (array_key_exists('latest_release', $state) ? null : 'check-error'), CheckHistorySnapshot::GIT_CODES),
            'release_http_status' => HistoryScalar::integer($state['release_http_status'] ?? null, 100, 599),
            'commit_error_code' => HistoryScalar::code($state['commit_error_code'] ?? (array_key_exists('latest_commit', $state) ? null : 'check-error'), CheckHistorySnapshot::GIT_CODES),
            'commit_http_status' => HistoryScalar::integer($state['commit_http_status'] ?? null, 100, 599)];
    }
}
