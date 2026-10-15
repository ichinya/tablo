<?php
declare(strict_types=1);

namespace Tablo;

// Worker-only unavailable credential fallback keeps health/version independent.
final class UnavailableRepositoryProvider implements RepositoryProvider
{
    public function getLatestRelease(string $repository): ?string { throw new GitHubFailure('unavailable'); }
    public function getLatestCommit(string $repository, string $branch): string { throw new GitHubFailure('unavailable'); }
    public function getOpenIssuesCount(string $repository): int { throw new GitHubFailure('unavailable'); }
    public function getOpenPullRequestsCount(string $repository): int { throw new GitHubFailure('unavailable'); }
}
