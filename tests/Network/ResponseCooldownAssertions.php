<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class ResponseCooldownAssertions
{
    public static function stored(array $rows, string $case, string $kind, int $now, int $delay): void
    {
        $primary = $case === 'primary429';
                    Assert::same(count($rows), $primary && $kind === 'saved' ? 2 : 1);
                    foreach ($rows as $row) {
                        Assert::same($row['resource'], $primary ? 'core' : 'secondary');
                        Assert::same((int) $row['eligible_at'], $now + $delay);
                        Assert::true($primary ? str_starts_with($row['scope'], 'credential:v1:') || str_starts_with($row['scope'], 'saved:')
                            : $row['scope'] === 'shared');
                    }

    }
}
