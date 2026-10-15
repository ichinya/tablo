<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-import-type GitCredentialRecord from SiteCredentialInput as CredentialRecord */
final class GitCredentialResolver
{
    public function __construct(#[\SensitiveParameter] private readonly SiteRepository $sites, private readonly bool $worker, private readonly bool $propagateSharedFailures) {}
    /** @param CredentialRecord|null $site */
    public function resolve(#[\SensitiveParameter] ?array $site, #[\SensitiveParameter] array $input): GitResolvedCredential
    {
        try {
            if ($this->worker) { $this->sites->assertWorkerKeyAvailable(); }
            $token = $this->sites->resolveToken($input, $site);
            $revision = $this->sites->credentialRevision($input, $site);
            $scope = $this->sites->credentialScope($input, $site);
            $equivalentScope = $this->sites->equivalentCredentialScope($token);
            if ($scope === 'authenticated' && $equivalentScope !== null) { $scope = $equivalentScope; }
            return new GitResolvedCredential($token, $revision, $scope, $equivalentScope);
        } catch (\RuntimeException $e) {
            if (($this->worker || $this->propagateSharedFailures) && ($e instanceof \PDOException || $e instanceof SharedKeyFailure)) { throw $e; }
            throw new ValidationException(['github_token' => $e->getMessage()]);
        }
    }

}
