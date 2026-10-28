<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type Row from SelfUpdateRow */
final class SelfUpdateProbe
{
    public function __construct(private readonly GitHubUpdateSource $source) {}
    /** @param Row $claim
     * @param \Closure(): bool $stop */
    public function check(BuildMetadata $build, array $claim, \Closure $stop): SelfUpdateResult
    {
        $latest = $this->source->latest($build);
        self::admit($stop);
        $comparison = $build->sha === $latest ? new GitHubComparison('identical', 0) : $this->source->compare($build->repository, $build->sha, $latest);
        $status = match ($comparison->status) { 'identical' => 'current', 'ahead' => 'behind', 'behind' => 'ahead', 'diverged' => 'diverged', default => 'unknown' };
        $previous = $claim['observed_sha'];
        if ($previous !== null && $previous !== $latest && $build->sha !== $latest) {
            self::admit($stop);
            $progress = $previous === $build->sha ? $comparison : $this->source->compare($build->repository, $previous, $latest);
            if (in_array($progress->status, ['behind', 'diverged'], strict: true)) { $status = 'force_pushed'; }
            if ($progress->status === 'unknown') { $status = 'unknown'; }
        }
        if ($claim['status'] === 'force_pushed' && $build->sha !== $latest) { $status = 'force_pushed'; }
        self::admit($stop);
        return new SelfUpdateResult($status, $latest, $status === 'behind' ? $comparison->ahead : null);
    }
    /** @param \Closure(): bool $stop */
    private static function admit(\Closure $stop): void { if ($stop()) { throw new GitHubFailure('stopped'); } }
}
