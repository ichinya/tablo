<?php
declare(strict_types=1);

// Only loaded with auto_prepend_file by the isolated CLI test. Actual bin/check.php,
// HTTP client, GitHub provider, checker and repository run; only GitHub transport is synthetic.
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/github-fixture.php';

final class FixtureCliGitHubConnection
{
    private readonly Tablo\GitHubRequestPolicy $policy;
    public function __construct(private readonly Tablo\SiteRepository $sites, Tablo\HttpClient $http)
    {
        $this->policy = new Tablo\GitHubRequestPolicy($sites->githubCooldowns());
    }

    public function provider(array $site): Tablo\GitHubProvider
    {
        return new Tablo\GitHubProvider(new FixtureGitHubHttp(), $this->sites->tokenFor($site), $this->policy,
            new Tablo\GitHubCredential($this->sites->credentialRevision([], $site), $this->sites->credentialScope([], $site),
                equivalentScope: $this->sites->equivalentCredentialScope($this->sites->tokenFor($site))));
    }
}

class_alias(FixtureCliGitHubConnection::class, 'Tablo\GitHubConnection');
