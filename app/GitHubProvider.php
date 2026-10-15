<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubProvider implements RepositoryProvider
{
    private readonly GitHubRequestPolicy $policy;
    private readonly string $identity;
    private readonly string $scope;
    private readonly GitHubCredential $credential;
    private readonly GitHubRequest $requests;
    private readonly GitHubMetric $metrics;

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter] private readonly string $token = '',
        ?GitHubRequestPolicy $policy = null,
        ?GitHubCredential $credential = null,
    ) {
        $this->policy = $policy ?? new GitHubRequestPolicy();
        $this->credential = $credential ?? new GitHubCredential(scope: $token === '' ? 'anonymous' : 'ephemeral:' . $this->policy->identity($token));
        $this->identity = $this->policy->identity($token, $this->credential->revision);
        $this->scope = $this->credential->scope;
        $this->requests = new GitHubRequest($this->http,$this->token,$this->policy,$this->credential);
        $this->metrics = new GitHubMetric($this->policy,$this->credential,$this->identity);
    }



    public function admissions(): int { return $this->policy->admissions(); }

    public function eligibleResources(): array
    {
        $this->credential->assertCurrent($this->policy, $this->identity);
        $result = [];
        foreach (['core', 'search'] as $resource) {
            $result[$resource] = $this->policy->currentEligibility($resource, $this->scope,
                $this->credential->equivalentScope) <= $this->policy->epoch();
        }
        return $result;
    }









    public function requireBranch(string $repository, string $branch): void
    {
        $this->getLatestCommit($repository, $branch);
    }

    public function getLatestRelease(string $repository): ?string
    {
        return $this->metrics->metric('release:' . GitRepositoryPath::path($repository), function () use ($repository): ?string {
        $json = $this->requests->request(GitRepositoryPath::path($repository) . '/releases/latest', GitMissingPolicy::Allowed);
        if ($json === null) {
            // Distinguish a repository with no releases from a missing/private repository.
            $this->requests->request(GitRepositoryPath::path($repository));
            return null;
        }
        $tag = $json['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '' || strlen($tag) > 200) {
            throw new GitHubFailure('release-unconfirmed');
        }
        return $tag;
        });
    }

    public function getLatestCommit(string $repository, string $branch): string
    {
        return $this->metrics->metric('commit:' . GitRepositoryPath::path($repository) . '/branches/' . rawurlencode($branch),
            function () use ($repository, $branch): string {
        $json = $this->requests->request(GitRepositoryPath::path($repository) . '/branches/' . rawurlencode($branch));
        if (!is_string($json['name'] ?? null) || !is_string($json['commit']['sha'] ?? null)
            || !preg_match('/^[a-f0-9]{40,64}$/iD', $json['commit']['sha'])) {
            throw new GitHubFailure('invalid-data');
        }
        if ($json['name'] !== $branch) { throw new GitHubFailure('branch-unconfirmed'); }
        return strtolower($json['commit']['sha']);
        });
    }



    public function getOpenIssuesCount(string $repository): int { return (new GitHubCount($this->metrics,$this->requests))->count($repository, 'issue'); }
    public function getOpenPullRequestsCount(string $repository): int { return (new GitHubCount($this->metrics,$this->requests))->count($repository, 'pr'); }
    public function getBranches(string $repository): array
    { return $this->metrics->metric('branches:' . GitRepositoryPath::path($repository), fn (): array => (new GitHubBranches($this->requests->request(...)))->getBranches($repository)); }
    public function beginCheck(): void { $this->requests->beginCheck(); }
}
