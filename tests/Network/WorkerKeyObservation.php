<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;

final class WorkerKeyObservation extends WorkerFailureFixtureCase
{
    public static function read(TemporaryDirectory $directory, \PDO $db): array
    {
        return [self::requests($directory),
            (int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL'))->fetchColumn()];
    }
}
