<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubCount
{
    public function __construct(private readonly GitHubMetric $metric, private readonly GitHubRequest $request) {}
    public function count(string $repository, string $kind): int
    {
        return $this->metric->metric('count:' . GitRepositoryPath::path($repository) . ':' . $kind, function () use ($repository, $kind): int {
        $json = $this->request->request('/search/issues?q=' . rawurlencode("repo:{$repository} is:{$kind} is:open") . '&per_page=1');
        if (($json['incomplete_results'] ?? true) !== false || !is_int($json['total_count'] ?? null) || $json['total_count'] < 0) {
            throw new GitHubFailure('incomplete-search');
        }
        return $json['total_count'];
        });
    }
}
