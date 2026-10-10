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

    public function admissions(): int { return $this->attempts; }

    // Observation only: identical maximum to before(), without admission or storage writes.
    public function currentEligibility(string $resource, string $scope, ?string $equivalentScope = null): int
    {
        return max($this->eligibility($resource, $scope),
            $equivalentScope === null ? 0 : $this->eligibility($resource, $equivalentScope),
            $this->eligibility('secondary', 'shared'));
    }

    public function epoch(): int { return $this->clock->epoch(); }

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

    public function before(string $resource, int $siteDeadline, string $scope = 'anonymous', ?string $equivalentScope = null): int
    {
        $deadline = min($this->deadline, $siteDeadline);
        if ($this->attempts >= $this->maxRequests || $this->now() >= $deadline) {
            throw new GitHubFailure('budget');
        }
        try {
            $primary = $this->eligibility($resource, $scope);
            if ($equivalentScope !== null) { $primary = max($primary, $this->eligibility($resource, $equivalentScope)); }
            // Retain observed equivalent-token eligibility on this handle before rotation.
            if ($primary > $this->clock->epoch()) { $this->defer($resource, $primary, $scope); }
            $eligible = max($primary, $this->eligibility('secondary', 'shared'));
        } catch (\Throwable) {
            throw new GitHubFailure('unavailable');
        }
        if ($eligible > $this->clock->epoch()) { throw new GitHubFailure('rate-limit', $eligible); }
        ++$this->attempts;
        return $deadline;
    }

    private function eligibility(string $resource, string $scope): int
    {
        return max($this->cooldowns[$scope . ':' . $resource] ?? 0,
            $this->storage?->eligibleAt($resource, $scope, false) ?? 0);
    }

    private function defer(string $resource, int $eligible, string $scope): void
    {
        $key = ($resource === 'secondary' ? 'shared' : $scope) . ':' . $resource;
        $this->cooldowns[$key] = max($this->cooldowns[$key] ?? 0, $eligible);
        try { $this->storage?->defer($resource, $eligible, $scope); }
        catch (\Throwable) { throw new GitHubFailure('unavailable'); }
    }

    public function observe(string $requestedResource, #[\SensitiveParameter] array $response, string $scope = 'anonymous', ?string $equivalentScope = null): void
    {
        $limit = GitHubRateLimit::fromResponse($requestedResource, $response, $this->clock->epoch());
        if ($limit === null) { return; }
        foreach ($limit->resources as $resource) {
            $this->defer($resource, $limit->eligibleAt, $scope);
            if ($resource !== 'secondary' && $equivalentScope !== null && $equivalentScope !== $scope) {
                $this->defer($resource, $limit->eligibleAt, $equivalentScope);
            }
        }
        if ($limit->limitedStatus) { throw new GitHubFailure('rate-limit', $limit->eligibleAt); }
    }
}
