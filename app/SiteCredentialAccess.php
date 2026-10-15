<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
class SiteCredentialAccess
{
    private readonly GitTokenRepository $savedTokens;

    public function __construct(private readonly \PDO $db, #[\SensitiveParameter] private readonly TokenVault $tokens)
    {
        $this->savedTokens = new GitTokenRepository($db, $tokens);
    }

    public function githubCooldowns(): GitHubCooldownRepository
    {
        return new GitHubCooldownRepository($this->db);
    }

    /** @param CredentialRecord|null $site */
    public function tokenFor(#[\SensitiveParameter] ?array $site): string
    {
        if (!!($site['git_token_id'] ?? null)) {
            return $this->savedTokens->tokenFor((int) $site['git_token_id'], $site['provider'] ?? 'github');
        }
        return !($site['github_token'] ?? null) ? '' : $this->tokens->decrypt($site['github_token']);
    }











    public function assertWorkerKeyAvailable(): void
    {
        $this->tokens->assertAvailable(KeyRequirement::forDatabase($this->db));
    }


    /** @param CredentialRecord|null $site */
    protected function selectedToken(#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $site): ?int { return (new SelectedSiteToken($this->savedTokens))->selectedToken($input,$site); }
    /** @param CredentialRecord|null $site */
    public function resolveToken(#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $site): string { return (new SiteTokenResolver($this->savedTokens,$this->tokens))->resolveToken($input,$site); }
    /** @param CredentialRecord|null $site */
    public function credentialRevision(#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $site): string { return (new SiteCredentialRevision($this->db,$this->savedTokens))->credentialRevision($input,$site); }
    /** @param CredentialRecord|null $site */
    public function credentialScope(#[\SensitiveParameter] array $input,#[\SensitiveParameter] ?array $site): string { return (new SiteCredentialIdentity($this->db,$this->savedTokens,$this->tokens))->credentialScope($input,$site); }
    public function equivalentCredentialScope(#[\SensitiveParameter] string $token): ?string { return (new SiteCredentialIdentity($this->db,$this->savedTokens,$this->tokens))->equivalentCredentialScope($token); }
}
