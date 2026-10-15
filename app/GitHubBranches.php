<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubBranches
{
    public function __construct(#[\SensitiveParameter] private readonly \Closure $request) {}
    public function getBranches(string $repository): array
    {
        $started = hrtime(true);
        $metadata = ($this->request)(GitRepositoryPath::path($repository));
        $default = $metadata['default_branch'] ?? null;
        if (!is_string($default) || $default === '' || strlen($default) > 128) {
            throw new GitHubFailure('invalid-data');
        }
        $branches = [];
        for ($page = 1; $page <= 10; ++$page) {
            if ((hrtime(true) - $started) / 1e9 > 24) {
                throw new GitHubFailure('branches-timeout');
            }
            $items = ($this->request)(GitRepositoryPath::path($repository) . '/branches?per_page=100&page=' . $page);
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
