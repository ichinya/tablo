<?php
declare(strict_types=1);

namespace Tablo;

final readonly class SelfUpdateResult
{
    public function __construct(public string $status, public ?string $latest = null, public ?int $count = null,
        public ?string $error = null, public ?int $eligibleAt = null) {}

    public static function failure(GitHubFailure | HttpFailure $failure): self
    {
        $reason = $failure instanceof GitHubFailure && in_array($failure->reason,
            ['rate-limit', 'budget', 'access', 'renamed', 'branch-unconfirmed', 'invalid-data', 'stopped'], strict: true) ? $failure->reason : 'unavailable';
        return new self('unknown', error: $reason, eligibleAt: $failure instanceof GitHubFailure ? $failure->eligibleAt : null);
    }
}
