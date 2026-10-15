<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use Tablo\JsonRecord;
use Tablo\SqlColumn;

/**
 * @phpstan-type Base array{version: int, ddl: ?int, journal: string, foreign_keys: int, ...}
 * @phpstan-type Write array{version: int, ddl: ?int, journal: string, foreign_keys: int, written: bool, elapsed: int|float, timeout: int, ...}
 * @phpstan-type Read array{version: int, ddl: ?int, journal: string, foreign_keys: int, before: int, during: int, after: int, ...}
 */
final class DatabaseProcessObservation
{
    /** @return Base */
    public static function decode(string $json): array
    {
        $row = JsonRecord::decode($json, 16);
        $row['version'] = SqlColumn::integer($row, 'version');
        $row['ddl'] = SqlColumn::nullableInteger($row, 'ddl');
        $row['journal'] = SqlColumn::string($row, 'journal');
        $row['foreign_keys'] = SqlColumn::integer($row, 'foreign_keys');
        return $row;
    }

    /** @param Base $row
     * @return Write */
    public static function write(array $row): array
    {
        $row['written'] = FixtureJson::boolean($row, 'written');
        $row['elapsed'] = FixtureJson::number($row, 'elapsed');
        $row['timeout'] = SqlColumn::integer($row, 'timeout');
        if (!$row['written']) { $row['sqlite_code'] = FixtureJson::sqliteCode($row, 'sqlite_code'); }
        return $row;
    }

    /** @param Base $row
     * @return Read */
    public static function read(array $row): array
    {
        $row['before'] = SqlColumn::integer($row, 'before');
        $row['during'] = SqlColumn::integer($row, 'during');
        $row['after'] = SqlColumn::integer($row, 'after');
        return $row;
    }
}
