<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Field array{eligible_at: ?string, has_value: ?bool, http_status: ?int, reason: string, status: string, value: ?int} */
final class GitFieldOutput
{
    private const GIT_REASONS = ['ok', 'no-release', 'access', 'rate-limit', 'budget', 'credential-changed',
        'renamed', 'branch-unconfirmed', 'branches-too-large', 'branches-empty', 'branches-timeout',
        'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable'];
    /** @return Field */
    public static function project(string $field, #[\SensitiveParameter] array $state): array
    {
        return self::outcome($state['git_outcomes'][$field] ?? null, $field, $state);
    }

    /** @return Field */
    private static function outcome(#[\SensitiveParameter] mixed $outcome, string $field, #[\SensitiveParameter] array $state): array
    {
        if (!is_array($outcome)) { CheckScalar::invalid(); }
        $status = CheckScalar::choice($outcome['status'] ?? null, ['ok', 'absent', 'failed']);
        $reason = CheckScalar::choice($outcome['reason'] ?? null, self::GIT_REASONS);
        return self::value($state[$field] ?? null, $field, $status, $reason, $outcome);
    }

    /** @return Field */
    private static function value(#[\SensitiveParameter] mixed $value, string $field, string $status, string $reason, #[\SensitiveParameter] array $outcome): array
    {
        $ok = in_array($status, ['ok', 'absent'], strict: true);
        GitOutputInvariant::validate($field, $value, $status, $reason);
        $count = in_array($field, ['open_issues', 'open_prs'], strict: true) ? CheckScalar::integer($value) : null;
        $hasValue = $ok ? ($count !== null || CheckScalar::present($value)) : null;
        $eligible = CheckScalar::integer($outcome['eligible_at'] ?? null);
        $eligibleAt = $eligible === null ? null : OutputTimestamp::timestamp(gmdate('Y-m-d\TH:i:s\Z', $eligible));
        if ($eligible !== null && $eligibleAt === null) { CheckScalar::invalid(); }
        return ['status' => $status, 'reason' => $reason,
            'http_status' => CheckScalar::integer($outcome['http_status'] ?? null, 100, 599),
            'eligible_at' => $eligibleAt, 'has_value' => $hasValue, 'value' => $count];
    }

}
