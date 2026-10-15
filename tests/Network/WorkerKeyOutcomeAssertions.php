<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class WorkerKeyOutcomeAssertions
{
    public static function refused(array $result, array $observation, string $kind, string $failure): void
    {
                Assert::same($result['exit_code'], 1, $kind);
                Assert::same($result['stderr'], $failure, $kind);
                Assert::false(str_contains($result['stdout'], 'Worker pass complete.'));
                Assert::same($observation[0], str_starts_with($kind, 'live') ? ['/barrier'] : [], $kind);
                Assert::same($observation[1], 0);

    }
}
