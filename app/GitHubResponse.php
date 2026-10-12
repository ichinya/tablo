<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Frame from GitHubFrame */
final class GitHubResponse
{
    /** @param Frame $response */
    public static function decode(#[\SensitiveParameter] array $response, GitMissingPolicy $missing): ?array
    {
        if ($missing === GitMissingPolicy::Allowed && $response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $reason = match ($response['status']) {
                301, 302 => 'renamed',
                401, 403, 404 => 'access',
                default => 'unavailable',
            };
            throw new GitHubFailure($reason, httpStatus: $response['status']);
        }
        return self::record(json_decode($response['body'], associative: true, depth: 32));
    }

    private static function record(mixed $json): array
    {
        if (!is_array($json)) {
            throw new GitHubFailure('invalid-data');
        }
        return $json;
    }
}
