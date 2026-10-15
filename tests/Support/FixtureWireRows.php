<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\JsonRecord;
use Tablo\SqlColumn;

/**
 * @phpstan-type Redirect array{route: string, bearer: bool, authorized: bool, unexpected_headers: bool, method_get: bool, ...}
 * @phpstan-type Endpoint array{path: string, present: bool, a: bool, b: bool, git: bool, ...}
 * @phpstan-type Webhook array{count: int, exact: bool, authorization: bool, same_key: bool, redirect_targets: int, ...}
 */
final class FixtureWireRows
{
    /** @return Redirect */
    public static function redirect(string $line): array
    {
        $row = JsonRecord::checked(json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR));
        $row['route'] = SqlColumn::string($row, 'route');
        $row['bearer'] = FixtureJson::boolean($row, 'bearer');
        $row['authorized'] = FixtureJson::boolean($row, 'authorized');
        $row['unexpected_headers'] = FixtureJson::boolean($row, 'unexpected_headers');
        $row['method_get'] = FixtureJson::boolean($row, 'method_get');
        if (array_key_exists('request_line', $row)) {
            $row['request_line'] = SqlColumn::string($row, 'request_line');
            return $row;
        }
        return $row;
    }

    /** @return Endpoint */
    public static function endpoint(string $line): array
    {
        $row = JsonRecord::checked(json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR));
        $row['path'] = SqlColumn::string($row, 'path');
        $row['present'] = FixtureJson::boolean($row, 'present');
        $row['a'] = FixtureJson::boolean($row, 'a');
        $row['b'] = FixtureJson::boolean($row, 'b');
        $row['git'] = FixtureJson::boolean($row, 'git');
        return $row;
    }

    /** @return Webhook */
    public static function webhook(string $line): array
    {
        $row = JsonRecord::checked(json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR));
        $row['count'] = SqlColumn::integer($row, 'count');
        $row['exact'] = FixtureJson::boolean($row, 'exact');
        $row['authorization'] = FixtureJson::boolean($row, 'authorization');
        $row['same_key'] = FixtureJson::boolean($row, 'same_key');
        $row['redirect_targets'] = SqlColumn::integer($row, 'redirect_targets');
        return $row;
    }
}
