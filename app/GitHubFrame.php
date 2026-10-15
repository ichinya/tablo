<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Frame array{status: int, body: string, ...} */
final class GitHubFrame
{
    /** @return Frame */
    public static function checked(#[\SensitiveParameter] array $response): array
    {
        if (!is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)
            || $response['status'] < 100 || $response['status'] > 599) { throw new GitHubFailure('unavailable'); }
        return $response;
    }
}
