<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type MemoValue from GitHubMemo
 * @phpstan-import-type Branches from GitHubBranches
 */
final class GitMetricValue
{
    /** @param MemoValue $value */
    public static function release(mixed $value): ?string
    {
        if ($value === null || is_string($value)) { return $value; }
        throw new \TypeError('Invalid cached release type.');
    }
    /** @param MemoValue $value */
    public static function commit(mixed $value): string
    {
        if (is_string($value)) { return $value; }
        throw new \TypeError('Invalid cached commit type.');
    }
    /** @param MemoValue $value */
    public static function count(mixed $value): int
    {
        if (is_int($value)) { return $value; }
        throw new \TypeError('Invalid cached count type.');
    }
    /**
     * @param MemoValue $value
     * @return Branches
     */
    public static function branches(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        throw new \TypeError('Invalid cached branches type.');
    }
}
