<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubCredential
{
    public function __construct(
        public readonly string $revision = '',
        public readonly string $scope = 'anonymous',
        private readonly ?\Closure $current = null,
        public readonly ?string $equivalentScope = null,
    ) {}

    public function assertCurrent(GitHubRequestPolicy $policy, string $identity): void
    {
        if ($this->current !== null && !($this->current)()) {
            $policy->invalidate($identity);
            throw new GitHubFailure('credential-changed');
        }
    }
}
