<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubUpdateSource
{
    private readonly GitHubRequest $requests;
    public function __construct(HttpClient $http, GitHubRequestPolicy $policy)
    {
        $this->requests = new GitHubRequest($http, '', $policy, new GitHubCredential(scope: 'anonymous'));
    }
    public function latest(BuildMetadata $build): string
    {
        // Reuse request/cooldown/redirect policy without a metric catch that normalizes runtime failures.
        $json = $this->requests->request(GitRepositoryPath::path($build->repository) . '/branches/' . rawurlencode($build->branch));
        if (!is_string($json['name'] ?? null) || !is_string($json['commit']['sha'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/iD', $json['commit']['sha']) !== 1) { throw new GitHubFailure('invalid-data'); }
        if ($json['name'] !== $build->branch) { throw new GitHubFailure('branch-unconfirmed'); }
        return strtolower($json['commit']['sha']);
    }
    public function compare(string $repository, string $base, string $head): GitHubComparison
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $base) !== 1 || preg_match('/\A[0-9a-f]{40}\z/D', $head) !== 1) {
            throw new GitHubFailure('invalid-data');
        }
        $comparison = GitHubComparison::checked($this->requests->request(GitRepositoryPath::path($repository) . '/compare/' . $base . '...' . $head, GitMissingPolicy::Allowed));
        if ($base !== $head && $comparison->status === 'identical') { throw new GitHubFailure('invalid-data'); }
        return $comparison;
    }
}
