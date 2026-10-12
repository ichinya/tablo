<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\RepositoryProvider;
use RuntimeException;

final class FakeProvider implements RepositoryProvider
{
    public bool $fail = false;
    public function getLatestRelease(string $repository): ?string
    {
        if ($this->fail) { throw new RuntimeException('rate limit'); }
        return 'v1.3.2';
    }
    public function getLatestCommit(string $repository, string $branch): string { return str_repeat('a', times: 40); }
    public function getOpenIssuesCount(string $repository): int { return 4; }
    public function getOpenPullRequestsCount(string $repository): int { return 1; }
}
