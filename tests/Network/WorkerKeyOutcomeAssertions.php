<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class WorkerKeyOutcomeAssertions
{
    public static function refused(array $result, array $observation, string $kind, string $failure): void
    {
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'exit_code'), 1, $kind);
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($result, 'stderr'), $failure, $kind);
                Assert::false(str_contains(\Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::at($result, 'stdout')), 'Worker pass complete.'));
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($observation, 0), str_starts_with($kind, 'live') ? ['/barrier'] : [], $kind);
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($observation, 1), 0);

    }
}
