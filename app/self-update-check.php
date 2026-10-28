<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SelfUpdateCheck
{
    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly \PDO $db, private readonly HttpClient $http, private readonly ?\Closure $clock = null, private readonly ?BuildMetadata $build = null) {}

    /** Network work is bounded to three requests and outside short claim/settlement transactions.
     * @param \Closure(): bool $stop */
    public function runDue(\Closure $stop): void
    {
        if ($stop()) { return; }
        $now = ($this->clock ?? time(...))(); $build = $this->build ?? BuildMetadata::configured();
        $repository = new SelfUpdateRepository($this->db); $claim = $repository->claim($build, $now);
        if ($claim === null) { return; }
        if (!$build->valid()) { $repository->complete($claim, $build, $now, new SelfUpdateResult('no_data')); return; }
        try {
            $policy = new GitHubRequestPolicy(new GitHubCooldownRepository($this->db), maxRequests: 3, durationMs: 24_000,
                clock: new GitHubClock(epoch: $this->clock));
            $result = (new SelfUpdateProbe(new GitHubUpdateSource($this->http, $policy)))->check($build, $claim, $stop);
        } catch (\Throwable $failure) {
            $result = SelfUpdateResult::failure($failure);
            unset($failure);
        }
        $repository->complete($claim, $build, ($this->clock ?? time(...))(), $result);
    }
}
