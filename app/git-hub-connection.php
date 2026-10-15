<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class GitHubConnection
{
    private readonly GitHubRequestPolicy $policy;

    public function __construct(#[\SensitiveParameter] private readonly SiteRepository $sites, private readonly HttpClient $http, ?GitHubRequestPolicy $policy = null,
        private readonly bool $worker = false, private readonly bool $propagateSharedFailures = false)
    {
        $this->policy = $policy ?? new GitHubRequestPolicy($sites->githubCooldowns());
    }

    /** @param CredentialRecord|null $site */
    public function provider(#[\SensitiveParameter] ?array $site, #[\SensitiveParameter] array $input = []): GitHubProvider
    {
        $site = SiteProjection::gitCredentials($site);
        $input = SiteProjection::withoutEndpointCredential($input);
        GitProviders::requireSupported($site['provider'] ?? 'github');
        $proof = (new GitCredentialResolver($this->sites, $this->worker, $this->propagateSharedFailures))->resolve($site, $input);
        $current = new GitCredentialCurrent($this->sites, $site, $input, $proof, $this->worker);
        return new GitHubProvider($this->http, $proof->token, $this->policy, new GitHubCredential($proof->revision, $proof->scope, $current->matches(...), $proof->equivalentScope));
    }

    /** @param CredentialRecord|null $site */
    public function branches(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site = null): array
    {
        $repository = SiteInput::normalizeRepository(is_string($input['repository'] ?? null) ? $input['repository'] : '');
        $provider = $this->provider($site, $input);
        try {
            return $provider->getBranches($repository);
        } catch (\Throwable $e) {
            throw new ValidationException(['repository' => $e->getMessage()]);
        }
    }

    /** @param CredentialRecord|null $site */
    public function validate(#[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $site = null): void
    {
        $data = SiteInput::normalize($input);
        $provider = $this->provider($site, $input);
        try {
            $provider->requireBranch($data['repository'], $data['branch']);
        } catch (\Throwable $e) {
            throw new ValidationException(['branch' => $e->getMessage()]);
        }
    }
}
