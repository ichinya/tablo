<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Testo\Assert;

final class WalRaceAssertions
{
    public static function observed(array $result): void
    {
                foreach (['A', 'B'] as $name) {
                    Assert::same($result[$name]['code'], 0, 'Scheduled actual connect failed: ' . $result[$name]['stdout']);
                    Assert::same($result[$name]['data']['version'], Database::CURRENT_SCHEMA_VERSION);
                    Assert::same($result[$name]['data']['journal'], 'wal');
                    Assert::same($result[$name]['data']['timeout'], 5000);
                    Assert::same($result[$name]['data']['foreignKeys'], 1);
                    Assert::same($result[$name]['data']['errorMode'], \PDO::ERRMODE_EXCEPTION);
                    Assert::same($result[$name]['data']['fetchMode'], \PDO::FETCH_ASSOC);
                    Assert::same($result[$name]['data']['returnedPdoReleased'], true);
                }
                $busy = array_values(array_filter($result['A']['events'], static fn (array $event): bool => $event['stage'] === 'attempt-finished'));
                Assert::same(count($busy), 1);
                Assert::same([$busy[0]['sqliteCode'], $busy[0]['cleanupFailed'], $busy[0]['statementRetained']], [5, false, false]);

    }
}
