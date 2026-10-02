<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubConnection
{
    public function __construct(private readonly SiteRepository $sites, private readonly HttpClient $http) {}

    public function provider(?array $site, array $input = []): GitHubProvider
    {
        GitProviders::requireSupported($site['provider'] ?? 'github');
        try {
            $token = $this->sites->resolveToken($input, $site);
        } catch (\RuntimeException $e) {
            throw new ValidationException(['github_token' => $e->getMessage()]);
        }
        return new GitHubProvider($this->http, $token);
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
