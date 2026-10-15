<?php
declare(strict_types=1);

namespace Tablo;

interface RepositoryProvider
{
    public function getLatestRelease(string $repository): ?string;
    public function getLatestCommit(string $repository, string $branch): string;
    public function getOpenIssuesCount(string $repository): int;
    public function getOpenPullRequestsCount(string $repository): int;
}
