<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Frame from GitHubFrame */
final class GitCooldownLedger
{
    /** @var array<string, int> */
    private array $cooldowns = [];
    public function __construct(private readonly ?GitHubCooldownRepository $storage, private readonly GitHubClock $clock) {}
    public function eligibility(string $resource, string $scope): int
    {
        return max($this->cooldowns[$scope . ':' . $resource] ?? 0,
            $this->storage?->eligibleAt($resource, $scope, false) ?? 0);
    }
    public function defer(string $resource, int $eligible, string $scope): void
    {
        $key = ($resource === 'secondary' ? 'shared' : $scope) . ':' . $resource;
        $this->cooldowns[$key] = max($this->cooldowns[$key] ?? 0, $eligible);
        try { $this->storage?->defer($resource, $eligible, $scope); }
        catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable) { throw new GitHubFailure('unavailable'); }
    }
    /** @param Frame $response */
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
    public function currentEligibility(string $resource, string $scope, ?string $equivalentScope = null): int
    {
        return max($this->eligibility($resource, $scope),
            $equivalentScope === null ? 0 : $this->eligibility($resource, $equivalentScope),
            $this->eligibility('secondary', 'shared'));
    }

}
