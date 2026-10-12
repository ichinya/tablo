<?php
declare(strict_types=1);

namespace Tablo;

// Decide from affirmative evidence only; unusable metadata is not a secondary limit.
/** @phpstan-import-type Frame from GitHubFrame */
final class GitHubRateLimit
{
    /** @param array<int, string> $resources */
    private function __construct(
        public readonly array $resources,
        public readonly int $eligibleAt,
        public readonly bool $limitedStatus,
    ) {}





    /** @param Frame $response */
    public static function fromResponse(string $requestedResource, #[\SensitiveParameter] array $response, int $now): ?self
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $remaining = GitRateEvidence::integer($headers['x-ratelimit-remaining'] ?? null);
        $primary = $remaining === 0;
        $retry = GitRateEvidence::integer($headers['retry-after'] ?? null);
        $limitedStatus = in_array($response['status'], [403, 429], strict: true);
        $secondary = GitSecondaryEvidence::limited($response,$retry,$remaining);
        if (!$primary && !$secondary) { return null; }
        $reset = GitRateEvidence::integer($headers['x-ratelimit-reset'] ?? null);
        $resource = in_array($headers['x-ratelimit-resource'] ?? null, ['core', 'search'], strict: true)
            ? $headers['x-ratelimit-resource'] : $requestedResource;
        return new self($secondary ? ['secondary'] : array_unique([$resource, $requestedResource]),
            max($now + 60, $reset ?? 0, $retry === null ? 0 : $now + $retry), $limitedStatus);
    }
}
