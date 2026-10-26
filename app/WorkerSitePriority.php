<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerSitePriority
{
    public function __construct(private readonly SiteRepository $sites,private readonly WorkerStateRepository $state) {}
    /** @return list<array{0: int, 1: int, 2: array-key}> The SQL ID projection preserves its native result indices. */
    public function ordered(GitHubConnection $connection): array
    {
        $priorities = [];
        foreach ($this->sites->enabledIds() as $position => $id) {
            $site = $this->sites->find($id);
            if ($site === null || !$site['enabled']) { continue; }
            try {
                $eligible = $connection->provider(SiteProjection::gitCredentials($site))->eligibleResources();
                $turns = $this->state->turns($id, $site['config_revision']);
                $available = array_filter($turns, static fn (string $field): bool =>
                    in_array($field, ['open_issues', 'open_prs'], strict: true) ? $eligible['search'] : $eligible['core'], ARRAY_FILTER_USE_KEY);
                $priority = $available === [] ? PHP_INT_MAX : min($available);
            } catch (\PDOException | DatabaseFailure | SharedKeyFailure $error) { throw $error; }
            catch (\Throwable) { $priority = PHP_INT_MAX; }
            $priorities[] = [$id, $priority, $position];
        }
        usort($priorities, static fn ($left, $right): int => [$left[1], $left[2]] <=> [$right[1], $right[2]]);
        return $priorities;
    }
}
