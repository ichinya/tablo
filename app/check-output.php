<?php
declare(strict_types=1);

namespace Tablo;

/** Public scalar projection; never a provider or database row serializer. */
final class CheckOutput
{
    public const GIT_FIELDS = ['latest_release', 'latest_commit', 'open_issues', 'open_prs'];
    private const GIT_REASONS = ['ok', 'no-release', 'access', 'rate-limit', 'budget', 'credential-changed',
        'renamed', 'branch-unconfirmed', 'branches-too-large', 'branches-empty', 'branches-timeout',
        'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable'];

    public static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $value : null;
    }

    private static function freshness(?string $timestamp, int $now): string
    {
        return $timestamp === null ? 'unavailable' : ($now - strtotime($timestamp) > 900 ? 'stale' : 'fresh');
    }

    private static function integer(mixed $value, int $min = 0, ?int $max = null): ?int
    {
        if ($value === null) { return null; }
        if (!is_int($value) || $value < $min || ($max !== null && $value > $max)) { self::invalid(); }
        return $value;
    }

    private static function choice(mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) { self::invalid(); }
        return $value;
    }

    private static function present(mixed $value): bool
    {
        if ($value !== null && !is_string($value)) { self::invalid(); }
        return $value !== null;
    }

    private static function invalid(): never { throw new \UnexpectedValueException('Invalid check output.'); }

    public static function site(#[\SensitiveParameter] array $site, string $status, string $reason, ?bool $attention,
        #[\SensitiveParameter] ?array $state = null, ?bool $stored = null, string $persistence = 'not-attempted', ?int $now = null): array
    {
        $now ??= time();
        $id = self::integer($site['id'] ?? null, 1);
        if ($id === null) { self::invalid(); }
        $previous = self::timestamp($site['checked_at'] ?? null);
        $previousFreshness = ($site['checked_at'] ?? null) === null ? 'never'
            : ($previous === null ? 'unknown' : self::freshness($previous, $now));
        $observation = null;
        if ($state !== null) {
            $checked = self::timestamp($state['checked_at'] ?? null);
            if ($checked === null) { self::invalid(); }
            $online = self::integer($state['online'] ?? null, 0, 1);
            $healthReason = $state['health_error_code'] ?? null;
            $healthReason = $healthReason === null ? 'ok' : self::choice($healthReason, [...array_keys(HttpFailure::MESSAGES), 'http']);
            $version = $state['version_outcome'] ?? null;
            if (!is_array($version)) { self::invalid(); }
            $versionStatus = self::choice($version['status'] ?? null, ['ok', 'failed', 'skipped']);
            $versionReason = self::choice($version['reason'] ?? null, [...array_keys(HttpFailure::MESSAGES), 'ok', 'http', 'invalid-data', 'not-configured']);
            $fields = [];
            $completed = 0;
            foreach (self::GIT_FIELDS as $field) {
                $outcome = $state['git_outcomes'][$field] ?? null;
                if (!is_array($outcome)) { self::invalid(); }
                $fieldStatus = self::choice($outcome['status'] ?? null, ['ok', 'absent', 'failed']);
                $fieldReason = self::choice($outcome['reason'] ?? null, self::GIT_REASONS);
                $value = $state[$field] ?? null;
                $ok = in_array($fieldStatus, ['ok', 'absent'], true);
                if (($fieldStatus === 'absent' && ($field !== 'latest_release' || $value !== null || $fieldReason !== 'no-release'))
                    || ($fieldStatus === 'ok' && ($value === null || $fieldReason !== 'ok'))
                    || ($fieldStatus === 'failed' && ($value !== null || in_array($fieldReason, ['ok', 'no-release'], true)))) { self::invalid(); }
                $count = in_array($field, ['open_issues', 'open_prs'], true) ? self::integer($value) : null;
                $hasValue = $ok ? ($count !== null || self::present($value)) : null;
                $eligible = self::integer($outcome['eligible_at'] ?? null);
                $eligibleAt = $eligible === null ? null : self::timestamp(gmdate('Y-m-d\TH:i:s\Z', $eligible));
                if ($eligible !== null && $eligibleAt === null) { self::invalid(); }
                $fields[$field] = ['status' => $fieldStatus, 'reason' => $fieldReason,
                    'http_status' => self::integer($outcome['http_status'] ?? null, 100, 599),
                    'eligible_at' => $eligibleAt, 'has_value' => $hasValue, 'value' => $count];
                $completed += (int) $ok;
            }
            $observation = ['checked_at' => $checked, 'freshness' => self::freshness($checked, $now),
                'health' => ['status' => match ($online) { 1 => 'online', 0 => 'offline', default => 'unknown' },
                    'reason' => $healthReason, 'http_status' => self::integer($state['health_http_status'] ?? null, 100, 599),
                    'response_time_ms' => self::integer($state['response_time_ms'] ?? null)],
                'version' => ['status' => $versionStatus, 'reason' => $versionReason,
                    'http_status' => self::integer($version['http_status'] ?? null, 100, 599),
                    'has_version' => $versionStatus === 'ok' ? self::present($state['deployed_version'] ?? null) : null,
                    'has_commit' => $versionStatus === 'ok' ? self::present($state['deployed_commit'] ?? null) : null],
                'git' => ['status' => $completed === 4 ? 'success' : ($completed === 0 ? 'failed' : 'partial'), 'fields' => $fields]];
        }
        return ['id' => $id, 'status' => $status, 'reason' => $reason, 'attention' => $attention,
            'observation' => $observation, 'persistence' => ['status' => $persistence, 'accepted' => $stored,
                'previous_checked_at' => $previous, 'previous_freshness' => $previousFreshness,
                'accepted_checked_at' => $stored === true ? $observation['checked_at'] : null]];
    }

    public static function encode(array $site): string { return json_encode($site, JSON_THROW_ON_ERROR); }

    public static function envelope(string $status, string $reason, ?string $started, array $fragments): string
    {
        $run = ['status' => $status, 'reason' => $reason, 'exit_code' => $status === 'success' ? 0 : 1,
            'started_at' => self::timestamp($started), 'finished_at' => self::timestamp(gmdate('Y-m-d\TH:i:s\Z'))];
        // The envelope has only fixed enums and validated ASCII timestamps. Assemble it
        // independently of the fragment encoder so its failure cannot erase prior sites.
        self::choice($status, ['success', 'partial', 'fatal']);
        self::choice($reason, ['attention', 'empty', 'complete', 'storage-failed', 'key-unavailable', 'check-failed', 'serialization-failed']);
        $start = $run['started_at'] === null ? 'null' : '"' . $run['started_at'] . '"';
        $finish = $run['finished_at'] === null ? 'null' : '"' . $run['finished_at'] . '"';
        return '{"schema_version":"tablo/check/v1","run":{"status":"' . $status . '","reason":"' . $reason
            . '","exit_code":' . $run['exit_code'] . ',"started_at":' . $start . ',"finished_at":' . $finish
            . '},"sites":[' . implode(',', $fragments) . "]}\n";
    }

    public static function fatalReason(\Throwable $error): string
    {
        return match (true) {
            $error instanceof \PDOException => 'storage-failed',
            $error instanceof SharedKeyFailure => 'key-unavailable',
            default => 'check-failed',
        };
    }

    public static function diagnose(\Throwable $error): void
    {
        fwrite(STDERR, "Check failed. Check installation configuration and local storage.\n");
        if ($error instanceof SharedKeyFailure && $error->getCode() === SharedKeyFailure::UNVERIFIED) {
            fwrite(STDERR, SharedKeyFailure::UNVERIFIED_DIAGNOSTIC . "\n");
        }
    }
}
