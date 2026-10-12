<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Testo\Assert;



/** @phpstan-import-type Snapshot from \Tablo\Tests\Support\DatabaseAssertions */
final class HistoricalRowsAssertions
{
    /** @param Snapshot $before
     * @param Snapshot $after */
    public static function retained(array $before, array $after): void
    {
                foreach ($before['rows'] as $table => $rows) {
                    foreach ($rows as $index => $row) {
                        Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::at($after['rows'], $table), $index), $row), $row, 'Changed existing ' . $table . ' row');
                    }
                }

    }
}
