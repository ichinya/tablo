<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type CheckResult from CheckState
 */
final class GitCheck
{
    public function __construct(private readonly RepositoryProvider $provider) {}
    /**
     * @param CheckResult $state
     * @param list<string> $errors
     * @param list<string>|null $fieldOrder
     */
    public function git(string $repository, string $branch, ?array $fieldOrder,
        #[\SensitiveParameter] array &$state, #[\SensitiveParameter] array &$errors): void
    {
        if ($this->provider instanceof GitHubProvider) { $this->provider->beginCheck(); }
        $calls = [
            'latest_release' => fn () => $this->provider->getLatestRelease($repository),
            'latest_commit' => fn () => $this->provider->getLatestCommit($repository, $branch),
            'open_issues' => fn () => $this->provider->getOpenIssuesCount($repository),
            'open_prs' => fn () => $this->provider->getOpenPullRequestsCount($repository),
        ];
        $service = [];
        foreach ($fieldOrder ?? array_keys($calls) as $field) {
            if (!(($calls[$field] ?? null) !== null)) { throw new \InvalidArgumentException('Invalid check field.'); }
            $call = $calls[$field];
            $service[$field] = (new GitFieldCheck($this->provider))->check($field, $call, $fieldOrder, $state, $errors);
        }
        if ($fieldOrder !== null) { $state['worker_service'] = $service; }
    }
}
