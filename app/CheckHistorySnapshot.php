<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;

/**
 * @phpstan-type Snapshot array{site_id: int, config_revision: int, checked_at: string, online: ?int,
 * health_error_code: ?string, health_http_status: ?int, response_time_ms: ?int,
 * deployed_version: ?string, deployed_commit: ?string, latest_release: ?string, latest_commit: ?string,
 * version_status: string, version_error_code: ?string, version_http_status: ?int,
 * release_error_code: ?string, release_http_status: ?int, commit_error_code: ?string, commit_http_status: ?int}
 */
final class CheckHistorySnapshot
{
    public const GIT_CODES = ['access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
        'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error'];

    /** @return Snapshot */
    public static function project(#[\SensitiveParameter] array $site, array $state): array
    {
        if (!is_int($site['id'] ?? null) || $site['id'] < 1 || !is_int($site['config_revision'] ?? null)
            || $site['config_revision'] < 0) { InvalidHistorySnapshot::invalid(); }
        try { $time = HistoryTime::canonical($state['checked_at'] ?? null); }
        catch (InvalidArgumentException) { InvalidHistorySnapshot::invalid(); }
        $versionStatus = self::versionStatus($state['version_status'] ?? (($site['version_path'] ?? '') === '' ? 'skipped' : 'error'));
        $row = ['site_id' => $site['id'], 'config_revision' => $site['config_revision'], 'checked_at' => $time,
            ...HistoryHealthProjection::project($state), ...HistoryVersionProjection::deployed($state),
            ...HistoryGitProjection::latest($state), ...HistoryVersionProjection::diagnostics($state,$versionStatus),
            ...HistoryGitProjection::diagnostics($state)];
        if (($versionStatus === 'error') !== ($row['version_error_code'] !== null)) { InvalidHistorySnapshot::invalid(); }
        return $row;
    }

    private static function versionStatus(mixed $value): string
    {
        if (!in_array($value, ['skipped', 'ok', 'error'], strict: true)) { InvalidHistorySnapshot::invalid(); }
        return $value;
    }










}
