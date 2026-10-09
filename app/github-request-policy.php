<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubRequestPolicy
{
    private array $memo = [];
    private array $cooldowns = [];
    private int $attempts = 0;
    private readonly string $salt;
    private readonly int $deadline;
    private readonly GitHubClock $clock;

    public function __construct(
        private readonly ?GitHubCooldownRepository $storage = null,
        private readonly int $maxRequests = 100,
        int $durationMs = 120000,
        private readonly int $maxEntries = 256,
        ?GitHubClock $clock = null,
    ) {
        if ($maxRequests < 0 || $maxRequests > 100 || $durationMs < 0 || $durationMs > 120000 || $maxEntries < 1 || $maxEntries > 256) {
            throw new \InvalidArgumentException('Invalid GitHub budget.');
        }
        $this->clock = $clock ?? new GitHubClock();
        $this->salt = random_bytes(32);
        $this->deadline = $this->now() + $durationMs * 1000000;
    }

    public function now(): int { return $this->clock->monotonic(); }

    public function identity(#[\SensitiveParameter] string $token, string $revision = ''): string
    {
        return hash_hmac('sha256', $token . "\0" . $revision, $this->salt);
    }

    public function invalidate(string $identity): void
    {
        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $identity . ':')) { unset($this->memo[$key]); }
        }
    }

    public function remember(string $identity, string $metric, \Closure $load): mixed
    {
        $key = $identity . ':' . hash('sha256', $metric);
        if (array_key_exists($key, $this->memo)) { return $this->memo[$key]; }
        $value = $load(); // Only a validated semantic result; exceptions never enter the cache.
        if (count($this->memo) >= $this->maxEntries) { array_shift($this->memo); }
        $this->memo[$key] = $value;
        return $value;
    }

    public function before(string $resource, int $siteDeadline, string $scope = 'anonymous'): int
    {
        $deadline = min($this->deadline, $siteDeadline);
        if ($this->attempts >= $this->maxRequests || $this->now() >= $deadline) {
            throw new GitHubFailure('budget');
        }
        try {
            $eligible = max($this->cooldowns[$scope . ':' . $resource] ?? 0, $this->cooldowns['shared:secondary'] ?? 0,
                $this->storage?->eligibleAt($resource, $scope) ?? 0);
        } catch (\Throwable) {
            throw new GitHubFailure('unavailable');
        }
        if ($eligible > $this->clock->epoch()) { throw new GitHubFailure('rate-limit', $eligible); }
        ++$this->attempts;
        return $deadline;
    }

    private static function integer(mixed $value): ?int
    {
        // Strings longer than the supported integer range are invalid, never wrapped.
        if (!is_string($value) || !preg_match('/^[0-9]{1,18}$/D', $value)) { return null; }
        return (int) $value;
    }

    public function observe(string $requestedResource, array $response, string $scope = 'anonymous'): void
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $remaining = self::integer($headers['x-ratelimit-remaining'] ?? null);
        $limitedStatus = in_array($response['status'], [403, 429], true);
        $primary = $remaining === 0;
        $secondary = $limitedStatus && (array_key_exists('retry-after', $headers) || ($response['status'] === 429 && !$primary)
            || (array_key_exists('x-ratelimit-remaining', $headers) && $remaining === null));
        if ($limitedStatus) {
            $document = json_decode($response['body'], true, 32);
            $marker = is_array($document) ? ($document['message'] ?? null) : null;
            $secondary = $secondary || (is_string($marker) && stripos($marker, 'secondary rate limit') !== false);
        }
        if (!$primary && !$secondary) { return; }
        $now = $this->clock->epoch();
        $reset = self::integer($headers['x-ratelimit-reset'] ?? null);
        $retry = self::integer($headers['retry-after'] ?? null);
        $eligible = max($now + 60, $reset ?? 0, $retry === null ? 0 : $now + $retry);
        $resource = in_array($headers['x-ratelimit-resource'] ?? null, ['core', 'search'], true)
            ? $headers['x-ratelimit-resource'] : $requestedResource;
        $resources = $secondary ? ['secondary'] : array_unique([$resource, $requestedResource]);
        foreach ($resources as $resource) {
            $key = ($resource === 'secondary' ? 'shared' : $scope) . ':' . $resource;
            $this->cooldowns[$key] = max($this->cooldowns[$key] ?? 0, $eligible);
            try { $this->storage?->defer($resource, $eligible, $scope); }
            catch (\Throwable) { throw new GitHubFailure('unavailable'); }
        }
        if ($limitedStatus) { throw new GitHubFailure('rate-limit', $eligible); }
    }
}
