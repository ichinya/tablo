<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubRequest
{
    private int $attempts = 0;
    private int $maxRequests = 11;
    private int $deadline;
    private readonly string $identity;
    private readonly string $scope;
    public function __construct(private readonly HttpClient $http, #[\SensitiveParameter] private readonly string $token, private readonly GitHubRequestPolicy $policy, private readonly GitHubCredential $credential)
    {
        $this->identity = $policy->identity($token,$credential->revision);
        $this->scope = $credential->scope;
        $this->deadline = $policy->now() + 24_000_000_000;
    }
    public function beginCheck(): void
    {
        $this->attempts = 0; $this->maxRequests = 5; $this->deadline = $this->policy->now() + 24_000_000_000;
    }
    public function request(string $path, GitMissingPolicy $missing = GitMissingPolicy::Required): ?array
    {
        $this->credential->assertCurrent($this->policy, $this->identity);
        if ($this->attempts >= $this->maxRequests) { throw new GitHubFailure('budget'); }
        $resource = str_starts_with($path, '/search/') ? 'search' : 'core';
        $deadline = $this->policy->before($resource, $this->deadline, $this->scope, $this->credential->equivalentScope);
        ++$this->attempts;
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $response = $this->http->getBefore('https://api.github.com' . $path, $headers, $deadline);
        if (!is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)
            || $response['status'] < 100 || $response['status'] > 599) { throw new GitHubFailure('unavailable'); }
        $this->policy->observe($resource, $response, $this->scope, $this->credential->equivalentScope);
        $this->credential->assertCurrent($this->policy, $this->identity);
        return GitHubResponse::decode($response,$missing);
    }
}
