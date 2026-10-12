<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Testo\Assert;



final class HistoricalRowsAssertions
{
    public static function retained(array $before, array $after): void
    {
                foreach ($before['rows'] as $table => $rows) {
                    foreach ($rows as $index => $row) {
                        Assert::same(array_intersect_key($after['rows'][$table][$index], $row), $row, 'Changed existing ' . $table . ' row');
                    }
                }

    }
}
