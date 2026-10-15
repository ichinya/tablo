<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class WorkerCredentialAssertions
{
    public static function settled(array $observation, string $mode): void
    {
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($observation, 0), in_array($mode, ['rename-only','stop'], strict: true));
                Assert::same(\Tablo\Tests\Support\FixtureValue::at($observation, 1),
                    in_array($mode, ['rename-only','stop'], strict: true) ? 1 : 0, 'real worker barrier pairs accepted result and history');

    }
}
