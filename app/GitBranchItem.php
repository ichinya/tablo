<?php
declare(strict_types=1);

namespace Tablo;

final class GitBranchItem
{
    public static function name(mixed $item): string
    {
        if (!is_array($item) || !is_string($item['name'] ?? null) || $item['name'] === ''
            || strlen($item['name']) > 128 || preg_match('/[\s\x00-\x1f]/', $item['name'])
            || !self::validSha($item['commit']['sha'] ?? '')) { throw new GitHubFailure('invalid-data'); }
        return $item['name'];
    }
    private static function validSha(mixed $sha): bool
    {
        // Keep the native preg_match wrong-type failure that the metric boundary classifies as unavailable.
        if (!is_string($sha)) { throw new \TypeError('Invalid branch commit type.'); }
        return preg_match('/^[a-f0-9]{40,64}$/iD', $sha) === 1;
    }
}
