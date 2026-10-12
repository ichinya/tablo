<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;


final class CallerTransactionFixture
{
    public static function open(\PDO $a, \PDO $b, string $key): void
    {
                    $a->beginTransaction();
                    $b->beginTransaction();
                    $a->exec('UPDATE installation_settings SET check_interval_minutes = 7');
                    $b->exec('UPDATE installation_settings SET check_interval_minutes = 8');
                    unlink($key); // Ownership refusal must precede even a key availability read.

    }
}
