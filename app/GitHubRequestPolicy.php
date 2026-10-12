<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubRequestPolicy
{
    private readonly GitHubMemo $memo;
    private readonly GitCooldownLedger $ledger;
    private int $attempts = 0;

    private readonly int $deadline;
    private readonly GitHubClock $clock;

    public function __construct(
        private readonly ?GitHubCooldownRepository $storage = null,
        private readonly int $maxRequests = 100,
        int $durationMs = 120_000,
        private readonly int $maxEntries = 256,
        ?GitHubClock $clock = null,
    ) {
        if ($maxRequests < 0 || $maxRequests > 100 || $durationMs < 0 || $durationMs > 120_000 || $maxEntries < 1 || $maxEntries > 256) {
            throw new \InvalidArgumentException('Invalid GitHub budget.');
        }
        $this->clock = $clock ?? new GitHubClock();
        $this->memo = new GitHubMemo($this->maxEntries);
        $this->ledger = new GitCooldownLedger($this->storage, $this->clock);
        $this->deadline = $this->now() + $durationMs * 1_000_000;
    }

    public function now(): int { return $this->clock->monotonic(); }

    public function admissions(): int { return $this->attempts; }

    // Observation only: identical maximum to before(), without admission or storage writes.


    public function epoch(): int { return $this->clock->epoch(); }







    public function before(string $resource, int $siteDeadline, string $scope = 'anonymous', ?string $equivalentScope = null): int
    {
        $deadline = min($this->deadline, $siteDeadline);
        if ($this->attempts >= $this->maxRequests || $this->now() >= $deadline) {
            throw new GitHubFailure('budget');
        }
        try {
            $primary = $this->ledger->eligibility($resource, $scope);
            if ($equivalentScope !== null) { $primary = max($primary, $this->ledger->eligibility($resource, $equivalentScope)); }
            // Retain observed equivalent-token eligibility on this handle before rotation.
            if ($primary > $this->clock->epoch()) { $this->ledger->defer($resource, $primary, $scope); }
            $eligible = max($primary, $this->ledger->eligibility('secondary', 'shared'));
        } catch (\PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable) {
            throw new GitHubFailure('unavailable');
        }
        if ($eligible > $this->clock->epoch()) { throw new GitHubFailure('rate-limit', $eligible); }
        ++$this->attempts;
        return $deadline;
    }






    public function identity(#[\SensitiveParameter] string $token, string $revision = ''): string { return $this->memo->identity($token, $revision); }
    public function invalidate(string $identity): void { $this->memo->invalidate($identity); }
    public function remember(string $identity, string $metric, \Closure $load): mixed { return $this->memo->remember($identity, $metric, $load); }
    public function currentEligibility(string $resource, string $scope, ?string $equivalentScope = null): int { return $this->ledger->currentEligibility($resource, $scope, $equivalentScope); }
    public function observe(string $requestedResource, #[\SensitiveParameter] array $response, string $scope = 'anonymous', ?string $equivalentScope = null): void { $this->ledger->observe($requestedResource, $response, $scope, $equivalentScope); }
}
