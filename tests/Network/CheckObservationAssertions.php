<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

/** @phpstan-import-type ObservationArray from \Tablo\Tests\Support\CheckSchema */
final class CheckObservationAssertions
{
    /** @param ObservationArray $observation */
    public static function independent(array $observation): void
    {
            Assert::same($observation['health']['status'], 'online'); Assert::same($observation['version']['status'], 'failed');
            Assert::same($observation['git']['fields']['latest_release']['status'], 'ok');
            Assert::same($observation['git']['fields']['latest_commit']['value'], null);
            Assert::same($observation['git']['fields']['open_prs']['value'], 0);

    }
    /** @param ObservationArray $observation */
    public static function limited(array $observation): void
    {
            Assert::same($observation['health']['status'], 'offline'); Assert::same($observation['health']['http_status'], 503);
            Assert::same($observation['version']['status'], 'ok'); Assert::same($observation['git']['status'], 'partial');
            Assert::same($observation['git']['fields']['latest_release']['reason'], 'rate-limit');
            Assert::true(is_string($observation['git']['fields']['latest_release']['eligible_at']));

    }
}
