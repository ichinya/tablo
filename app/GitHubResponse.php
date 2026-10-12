<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubResponse
{
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
        $json = json_decode($response['body'], associative: true, depth: 32);
        if (!is_array($json)) {
            throw new GitHubFailure('invalid-data');
        }
        return $json;
    }
}
