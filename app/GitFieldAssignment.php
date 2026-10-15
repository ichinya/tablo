<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type CheckResult from CheckState */
final class GitFieldAssignment
{
    /** @param CheckResult $state */
    public static function write(array &$state, string $field, string|int|null $value): void
    {
        if ($field === 'latest_release') { $state['latest_release'] = self::text($value); return; }
        if ($field === 'latest_commit') { $state['latest_commit'] = self::text($value); return; }
        if ($field === 'open_issues') { $state['open_issues'] = self::count($value); return; }
        if ($field === 'open_prs') { $state['open_prs'] = self::count($value); return; }
        throw new \InvalidArgumentException('Invalid check field.');
    }
    private static function text(string|int|null $value): ?string
    {
        if ($value === null || is_string($value)) { return $value; }
        throw new GitHubFailure('invalid-data');
    }
    private static function count(string|int|null $value): ?int
    {
        if ($value === null || is_int($value)) { return $value; }
        throw new GitHubFailure('invalid-data');
    }
}
