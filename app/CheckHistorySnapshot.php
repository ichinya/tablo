<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;

final class CheckHistorySnapshot
{
    public const GIT_CODES = ['access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
        'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error'];

    public static function project(#[\SensitiveParameter] array $site, array $state): array
    {
        if (!is_int($site['id'] ?? null) || $site['id'] < 1 || !is_int($site['config_revision'] ?? null)
            || $site['config_revision'] < 0) { InvalidHistorySnapshot::invalid(); }
        try { $time = HistoryTime::canonical($state['checked_at'] ?? null); }
        catch (InvalidArgumentException) { InvalidHistorySnapshot::invalid(); }
        $versionStatus = $state['version_status'] ?? (($site['version_path'] ?? '') === '' ? 'skipped' : 'error');
        if (!in_array($versionStatus, ['skipped', 'ok', 'error'], strict: true)) { InvalidHistorySnapshot::invalid(); }
        $row = ['site_id' => $site['id'], 'config_revision' => $site['config_revision'], 'checked_at' => $time,
            ...HistoryHealthProjection::project($state), ...HistoryVersionProjection::deployed($state),
            ...HistoryGitProjection::latest($state), ...HistoryVersionProjection::diagnostics($state,$versionStatus),
            ...HistoryGitProjection::diagnostics($state)];
        if (($versionStatus === 'error') !== ($row['version_error_code'] !== null)) { InvalidHistorySnapshot::invalid(); }
        return $row;
    }










}
