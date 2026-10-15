<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\GitHubFailure;
use Testo\Assert;

abstract class GitHubPrivacyFixtureCase
{
    protected static function failure(#[\SensitiveParameter] \Closure $call): GitHubFailure
    {
        $failure = null;
        try { $call(); } catch (GitHubFailure $caught) { $failure = $caught; }
        Assert::instanceOf($failure, GitHubFailure::class);
        Assert::same($failure->reason, 'rate-limit');
        return $failure;
    }

    protected static function assertSupportedProviderTrace(array $result): void
    {
        Assert::false(\Tablo\Tests\Support\FixtureValue::at($result, 'leaked'), 'response trace must redact every private marker');
        // Inherited production-bound closures remain opaque: supported state only, not whole-graph privacy.
        Assert::false(\Tablo\Tests\Support\FixtureValue::at($result, 'complete'), 'provider trace explicitly retains incomplete opaque coverage');
        Assert::true(\Tablo\Tests\Support\FixtureValue::integer($result['opaque']['closure'] ?? 0) > 0);
    }

    protected static function requests(string $path): array
    {
        return array_map('intval', \Tablo\Tests\Support\FixtureValue::fetched(file($path, FILE_IGNORE_NEW_LINES)));
    }
}
