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
            || $site['config_revision'] < 0) { self::invalid(); }
        try { $time = HistoryTime::canonical($state['checked_at'] ?? null); }
        catch (InvalidArgumentException) { self::invalid(); }
        $versionStatus = $state['version_status'] ?? (($site['version_path'] ?? '') === '' ? 'skipped' : 'error');
        if (!in_array($versionStatus, ['skipped', 'ok', 'error'], true)) { self::invalid(); }
        $row = ['site_id' => $site['id'], 'config_revision' => $site['config_revision'], 'checked_at' => $time,
            'online' => self::integer($state['online'] ?? null, 0, 1),
            'health_error_code' => self::code($state['health_error_code'] ?? null, [...array_keys(HttpFailure::MESSAGES), 'http']),
            'health_http_status' => self::integer($state['health_http_status'] ?? null, 100, 599),
            'response_time_ms' => self::integer($state['response_time_ms'] ?? null, 0, 2147483647),
            'deployed_version' => self::version($state['deployed_version'] ?? null),
            'deployed_commit' => self::commit($state['deployed_commit'] ?? null),
            'latest_release' => self::version($state['latest_release'] ?? null),
            'latest_commit' => self::commit($state['latest_commit'] ?? null),
            'version_status' => $versionStatus,
            'version_error_code' => self::code($state['version_error_code'] ?? ($versionStatus === 'error' ? 'check-error' : null),
                [...array_keys(HttpFailure::MESSAGES), 'http', 'invalid-data']),
            'version_http_status' => self::integer($state['version_http_status'] ?? null, 100, 599),
            'release_error_code' => self::code($state['release_error_code'] ?? (array_key_exists('latest_release', $state) ? null : 'check-error'), self::GIT_CODES),
            'release_http_status' => self::integer($state['release_http_status'] ?? null, 100, 599),
            'commit_error_code' => self::code($state['commit_error_code'] ?? (array_key_exists('latest_commit', $state) ? null : 'check-error'), self::GIT_CODES),
            'commit_http_status' => self::integer($state['commit_http_status'] ?? null, 100, 599)];
        if (($versionStatus === 'error') !== ($row['version_error_code'] !== null)) { self::invalid(); }
        return $row;
    }

    private static function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if ($value !== null && (!is_int($value) || $value < $minimum || $value > $maximum)) { self::invalid(); }
        return $value;
    }

    private static function code(mixed $value, array $codes): ?string
    {
        if ($value !== null && (!is_string($value) || !in_array($value, $codes, true))) { self::invalid(); }
        return $value;
    }

    private static function version(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || strlen($value) < 1 || strlen($value) > 200
            || preg_match('//u', $value) !== 1 || str_contains($value, "\0"))) { self::invalid(); }
        return $value;
    }

    private static function commit(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || !preg_match('/^[a-f0-9]{7,64}$/iD', $value))) { self::invalid(); }
        return $value === null ? null : strtolower($value);
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid check history snapshot.');
    }
}
