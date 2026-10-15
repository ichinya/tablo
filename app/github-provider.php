<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubProvider implements RepositoryProvider
{
    private readonly GitHubRequestPolicy $policy;
    private readonly string $identity;
    private readonly string $scope;
    private readonly GitHubCredential $credential;
    private int $attempts = 0;
    private int $maxRequests = 11;
    private int $deadline;

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
        $this->deadline = $this->policy->now() + 24000000000;
    }

    public function beginCheck(): void
    {
        $this->attempts = 0;
        $this->maxRequests = 5;
        $this->deadline = $this->policy->now() + 24000000000;
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

    private function metric(string $key, \Closure $load): mixed
    {
        try {
            $this->credential->assertCurrent($this->policy, $this->identity);
            $result = $this->policy->remember($this->identity, $key, function () use ($load): mixed {
                $value = $load();
                $this->credential->assertCurrent($this->policy, $this->identity);
                return $value;
            });
            $this->credential->assertCurrent($this->policy, $this->identity);
            return $result;
        } catch (GitHubFailure | \PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable) { throw new GitHubFailure('unavailable'); }
    }

    private function request(string $path, bool $allowMissing = false): ?array
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
        if ($allowMissing && $response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $reason = match ($response['status']) {
                301, 302 => 'renamed',
                401, 403, 404 => 'access',
                default => 'unavailable',
            };
            throw new GitHubFailure($reason, httpStatus: $response['status']);
        }
        $json = json_decode($response['body'], true, 32);
        if (!is_array($json)) {
            throw new GitHubFailure('invalid-data');
        }
        return $json;
    }

    private function path(string $repository): string
    {
        return '/repos/' . implode('/', array_map('rawurlencode', explode('/', $repository)));
    }

    public function getBranches(string $repository): array
    {
        return $this->metric('branches:' . $this->path($repository), function () use ($repository): array {
        $started = hrtime(true);
        $metadata = $this->request($this->path($repository));
        $default = $metadata['default_branch'] ?? null;
        if (!is_string($default) || $default === '' || strlen($default) > 128) {
            throw new GitHubFailure('invalid-data');
        }
        $branches = [];
        for ($page = 1; $page <= 10; ++$page) {
            if ((hrtime(true) - $started) / 1e9 > 24) {
                throw new GitHubFailure('branches-timeout');
            }
            $items = $this->request($this->path($repository) . '/branches?per_page=100&page=' . $page);
            if (!array_is_list($items) || count($items) > 100) {
                throw new GitHubFailure('invalid-data');
            }
            foreach ($items as $item) {
                $name = $item['name'] ?? null;
                if (!is_string($name) || $name === '' || strlen($name) > 128 || preg_match('/[\s\x00-\x1f]/', $name)
                    || !preg_match('/^[a-f0-9]{40,64}$/iD', $item['commit']['sha'] ?? '')) {
                    throw new GitHubFailure('invalid-data');
                }
                // Prefix keys so a numeric branch name remains a string in JSON.
                $branches['branch:' . $name] = $name;
            }
            if (count($items) < 100) {
                if (!$branches) {
                    throw new GitHubFailure('branches-empty');
                }
                return ['repository' => $repository, 'default_branch' => $default, 'branches' => array_values($branches)];
            }
        }
        throw new GitHubFailure('branches-too-large');
        });
    }

    public function requireBranch(string $repository, string $branch): void
    {
        $this->getLatestCommit($repository, $branch);
    }

    public function getLatestRelease(string $repository): ?string
    {
        return $this->metric('release:' . $this->path($repository), function () use ($repository): ?string {
        $json = $this->request($this->path($repository) . '/releases/latest', true);
        if ($json === null) {
            // Distinguish a repository with no releases from a missing/private repository.
            $this->request($this->path($repository));
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
        return $this->metric('commit:' . $this->path($repository) . '/branches/' . rawurlencode($branch),
            function () use ($repository, $branch): string {
        $json = $this->request($this->path($repository) . '/branches/' . rawurlencode($branch));
        if (!is_string($json['name'] ?? null) || !is_string($json['commit']['sha'] ?? null)
            || !preg_match('/^[a-f0-9]{40,64}$/iD', $json['commit']['sha'])) {
            throw new GitHubFailure('invalid-data');
        }
        if ($json['name'] !== $branch) { throw new GitHubFailure('branch-unconfirmed'); }
        return strtolower($json['commit']['sha']);
        });
    }

    private function count(string $repository, string $kind): int
    {
        return $this->metric('count:' . $this->path($repository) . ':' . $kind, function () use ($repository, $kind): int {
        $json = $this->request('/search/issues?q=' . rawurlencode("repo:$repository is:$kind is:open") . '&per_page=1');
        if (($json['incomplete_results'] ?? true) !== false || !is_int($json['total_count'] ?? null) || $json['total_count'] < 0) {
            throw new GitHubFailure('incomplete-search');
        }
        return $json['total_count'];
        });
    }

    public function getOpenIssuesCount(string $repository): int { return $this->count($repository, 'issue'); }
    public function getOpenPullRequestsCount(string $repository): int { return $this->count($repository, 'pr'); }
}
