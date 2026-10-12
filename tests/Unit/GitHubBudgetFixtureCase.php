<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\GitHubFailure;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;

abstract class GitHubBudgetFixtureCase
{
    /** @param array<string, string|null> $headers
     * @return array{status: int, body: string, time_ms: int, headers: array<string, string|null>} */
    protected static function response(int $status, string $body = '{}', array $headers = []): array
    {
        return UnitFixtures::response($status, $body) + ['headers' => $headers];
    }

    protected static function failure(\Closure $call, string $reason): GitHubFailure
    {
        $failure = null;
        try { $call(); } catch (GitHubFailure $caught) { $failure = $caught; }
        Assert::instanceOf($failure, GitHubFailure::class);
        $failure = \Tablo\Tests\Support\FixtureValue::object($failure);
        Assert::same($failure->reason, $reason);
        Assert::false(str_contains($failure->getMessage(), \Tablo\Tests\Support\FixtureCredentials::value(label: 'synthetic-secret')));
        return $failure;
    }
}
