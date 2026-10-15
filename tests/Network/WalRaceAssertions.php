<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Testo\Assert;

/** @phpstan-import-type Race from \Tablo\Tests\Support\DatabaseConnectSchedule */
final class WalRaceAssertions
{
    /** @param Race $result */
    public static function observed(array $result): void
    {
                foreach (['A', 'B'] as $name) {
                    $child = $name === 'A' ? $result['A'] : $result['B'];
                    Assert::same($child['code'], 0, 'Scheduled actual connect failed: ' . $child['stdout']);
                    $data = \Tablo\Tests\Support\ScheduleObservation::success($child['data']);
                    Assert::same($data['version'], Database::CURRENT_SCHEMA_VERSION);
                    Assert::same($data['journal'], 'wal');
                    Assert::same($data['timeout'], 5000);
                    Assert::same($data['foreignKeys'], 1);
                    Assert::same($data['errorMode'], \PDO::ERRMODE_EXCEPTION);
                    Assert::same($data['fetchMode'], \PDO::FETCH_ASSOC);
                    Assert::same($data['returnedPdoReleased'], true);
                }
                $busy = array_values(array_filter($result['A']['events'], static fn (array $event): bool => $event['stage'] === 'attempt-finished'));
                Assert::same(count($busy), 1);
                $attempt = \Tablo\Tests\Support\ScheduleObservation::attempt(\Tablo\Tests\Support\FixtureValue::at($busy, 0));
                Assert::same([$attempt['sqliteCode'], $attempt['cleanupFailed'], $attempt['statementRetained']], [5, false, false]);

    }
}
