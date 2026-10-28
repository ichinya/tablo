<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubUpdateSource
{
    private readonly GitHubProvider $provider;
    private readonly GitHubRequest $requests;
    public function __construct(HttpClient $http, GitHubRequestPolicy $policy)
    {
        $this->provider = new GitHubProvider($http, policy: $policy);
        $this->provider->beginCheck();
        $this->requests = new GitHubRequest($http, '', $policy, new GitHubCredential(scope: 'anonymous'));
    }
    public function latest(BuildMetadata $build): string { return $this->provider->getLatestCommit($build->repository, $build->branch); }
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
