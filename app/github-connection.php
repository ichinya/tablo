<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubConnection
{
    private readonly GitHubRequestPolicy $policy;

    public function __construct(private readonly SiteRepository $sites, private readonly HttpClient $http, ?GitHubRequestPolicy $policy = null)
    {
        $this->policy = $policy ?? new GitHubRequestPolicy($sites->githubCooldowns());
    }

    public function provider(?array $site, array $input = []): GitHubProvider
    {
        GitProviders::requireSupported($site['provider'] ?? 'github');
        try {
            $token = $this->sites->resolveToken($input, $site);
            $revision = $this->sites->credentialRevision($input, $site);
            $scope = $this->sites->credentialScope($input, $site);
        } catch (\RuntimeException $e) {
            throw new ValidationException(['github_token' => $e->getMessage()]);
        }
        // Resolve again against a fresh row before/after every metric, including memo hits.
        // This rejects same-second rotation and does not persist a credential or digest.
        $current = function () use ($site, $input, $token, $revision): bool {
            $fresh = isset($site['id']) ? $this->sites->find((int) $site['id']) : $site;
            if (isset($site['id']) && ($fresh === null
                || ($fresh['git_token_id'] ?? null) !== ($site['git_token_id'] ?? null)
                || ($fresh['github_token'] ?? null) !== ($site['github_token'] ?? null)
                || ($fresh['selected_token_snapshot'] ?? null) !== ($site['selected_token_snapshot'] ?? null))) { return false; }
            try { return hash_equals($token, $this->sites->resolveToken($input, $fresh))
                && hash_equals($revision, $this->sites->credentialRevision($input, $fresh)); }
            catch (\Throwable) { return false; }
        };
        return new GitHubProvider($this->http, $token, $this->policy, new GitHubCredential($revision, $scope, $current));
    }

    public function branches(array $input, ?array $site = null): array
    {
        $repository = SiteRepository::normalizeRepository(is_string($input['repository'] ?? null) ? $input['repository'] : '');
        $provider = $this->provider($site, $input);
        try {
            return $provider->getBranches($repository);
        } catch (\Throwable $e) {
            throw new ValidationException(['repository' => $e->getMessage()]);
        }
    }

    public function validate(array $input, ?array $site = null): void
    {
        $data = SiteRepository::normalize($input);
        $provider = $this->provider($site, $input);
        try {
            $provider->requireBranch($data['repository'], $data['branch']);
        } catch (\Throwable $e) {
            throw new ValidationException(['branch' => $e->getMessage()]);
        }
    }
}
