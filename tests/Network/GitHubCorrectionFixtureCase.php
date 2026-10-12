<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubFailure;
use Testo\Assert;

abstract class GitHubCorrectionFixtureCase
{
    protected static function failure(\Closure $call, string $reason): GitHubFailure
    {
        $error = null;
        try { $call(); } catch (GitHubFailure $caught) { $error = $caught; }
        Assert::instanceOf($error, GitHubFailure::class);
        Assert::same($error->reason, $reason);
        Assert::false(str_contains($error->getMessage(), 'fixture-secret'));
        Assert::false(str_contains($error->getMessage(), 'untrusted.example'));
        return $error;
    }
}
