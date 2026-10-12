<?php
declare(strict_types=1);

namespace Tablo;

/** @phpstan-type Branches array{repository: string, default_branch: string, branches: list<string>} */
final class GitHubBranches
{
    /** @param \Closure(string): ?array $request */
    public function __construct(#[\SensitiveParameter] private readonly \Closure $request) {}
    /** @return Branches */
    public function getBranches(string $repository): array
    {
        $started = hrtime(true);
        $metadata = ($this->request)(GitRepositoryPath::path($repository));
        if (!is_string($metadata['default_branch'] ?? null) || $metadata['default_branch'] === '' || strlen($metadata['default_branch']) > 128) {
            throw new GitHubFailure('invalid-data');
        }
        $default = $metadata['default_branch'];
        $branches = [];
        for ($page = 1; $page <= 10; ++$page) {
            if ((hrtime(true) - $started) / 1e9 > 24) {
                throw new GitHubFailure('branches-timeout');
            }
            $items = ($this->request)(GitRepositoryPath::path($repository) . '/branches?per_page=100&page=' . $page);
            if ($items === null) { throw new GitHubFailure('invalid-data'); }
            GitBranchItems::add($items,$branches);
            if (count($items) < 100) {
                if (!$branches) {
                    throw new GitHubFailure('branches-empty');
                }
                return ['repository' => $repository, 'default_branch' => $default, 'branches' => array_values($branches)];
            }
        }
        throw new GitHubFailure('branches-too-large');
    }
}
