<?php
declare(strict_types=1);

namespace Tablo;

// Decide from affirmative evidence only; unusable metadata is not a secondary limit.
final class GitHubRateLimit
{
    private function __construct(
        public readonly array $resources,
        public readonly int $eligibleAt,
        public readonly bool $limitedStatus,
    ) {}

    private static function integer(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,18}$/D', $value)) { return null; }
        return (int) $value;
    }

    private static function secondaryMessage(string $body): bool
    {
        $document = json_decode($body, true, 32);
        $message = is_array($document) ? ($document['message'] ?? null) : null;
        return is_string($message) && stripos($message, 'secondary rate limit') !== false;
    }

    public static function fromResponse(string $requestedResource, #[\SensitiveParameter] array $response, int $now): ?self
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $primary = self::integer($headers['x-ratelimit-remaining'] ?? null) === 0;
        $retry = self::integer($headers['retry-after'] ?? null);
        $limitedStatus = in_array($response['status'], [403, 429], true);
        $secondary = $limitedStatus && ($retry !== null || ($response['status'] === 429 && !$primary)
            || self::secondaryMessage($response['body']));
        if (!$primary && !$secondary) { return null; }
        $reset = self::integer($headers['x-ratelimit-reset'] ?? null);
        $resource = in_array($headers['x-ratelimit-resource'] ?? null, ['core', 'search'], true)
            ? $headers['x-ratelimit-resource'] : $requestedResource;
        return new self($secondary ? ['secondary'] : array_unique([$resource, $requestedResource]),
            max($now + 60, $reset ?? 0, $retry === null ? 0 : $now + $retry), $limitedStatus);
    }
}
