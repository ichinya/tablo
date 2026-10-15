<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\GitHubFailure;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;

abstract class GitHubBudgetFixtureCase
{
    protected static function response(int $status, string $body = '{}', array $headers = []): array
    {
        return UnitFixtures::response($status, $body) + ['headers' => $headers];
    }

    protected static function failure(\Closure $call, string $reason): GitHubFailure
    {
        $failure = null;
        try { $call(); } catch (GitHubFailure $caught) { $failure = $caught; }
        Assert::instanceOf($failure, GitHubFailure::class);
        Assert::same($failure->reason, $reason);
        Assert::false(str_contains($failure->getMessage(), \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')));
        return $failure;
    }
}
