<?php
declare(strict_types=1);

namespace Tablo;

final class GitFieldOutput
{
    private const GIT_REASONS = ['ok', 'no-release', 'access', 'rate-limit', 'budget', 'credential-changed',
        'renamed', 'branch-unconfirmed', 'branches-too-large', 'branches-empty', 'branches-timeout',
        'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable'];
    public static function project(string $field, #[\SensitiveParameter] array $state): array
    {
        $outcome = $state['git_outcomes'][$field] ?? null;
        if (!is_array($outcome)) { CheckScalar::invalid(); }
        $status = CheckScalar::choice($outcome['status'] ?? null, ['ok', 'absent', 'failed']);
        $reason = CheckScalar::choice($outcome['reason'] ?? null, self::GIT_REASONS);
        $value = $state[$field] ?? null;
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
