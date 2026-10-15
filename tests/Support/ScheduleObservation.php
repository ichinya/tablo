<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use Tablo\JsonRecord;
use Tablo\SqlColumn;

/**
 * @phpstan-type Common array{stage: string, elapsedMs: int|float, returnedPdoReleased: ?bool, ...}
 * @phpstan-type Success array{ok: true, version: int, journal: string, timeout: int, foreignKeys: int, errorMode: int, fetchMode: int, stage: string, elapsedMs: int|float, returnedPdoReleased: ?bool, ...}
 * @phpstan-type Failure array{ok: false, exception: string, sqliteCode: int|string|null, stage: string, elapsedMs: int|float, returnedPdoReleased: ?bool, ...}
 * @phpstan-type Data array{ok: bool, stage: string, elapsedMs: int|float, returnedPdoReleased: ?bool, ...}
 * @phpstan-type Event array{stage: string, ns: int, ...}
 * @phpstan-type Phase array{stage: string, ns: int, deadline: int, ...}
 * @phpstan-type Attempt array{stage: string, ns: int, sqliteCode: int|string|null, cleanupFailed: bool, statementRetained: bool, ...}
 */
final class ScheduleObservation
{
    /** @return Data */
    public static function decode(string $json): array
    {
        $row = JsonRecord::decode($json, 32);
        $row['ok'] = FixtureJson::boolean($row, 'ok');
        $row['stage'] = SqlColumn::string($row, 'stage');
        $row['elapsedMs'] = FixtureJson::number($row, 'elapsedMs');
        $row['returnedPdoReleased'] = FixtureJson::nullableBoolean($row, 'returnedPdoReleased');
        if ($row['ok']) { return self::success($row); }
        return self::failure($row);
    }

    /** @param Data|Common $row
     * @return Success */
    public static function success(array $row): array
    {
        if (FixtureJson::boolean($row, 'ok') !== true) { throw new \RuntimeException('Expected successful schedule observation.'); }
        $row['ok'] = true;
        $row['version'] = SqlColumn::integer($row, 'version');
        $row['journal'] = SqlColumn::string($row, 'journal');
        $row['timeout'] = SqlColumn::integer($row, 'timeout');
        $row['foreignKeys'] = SqlColumn::integer($row, 'foreignKeys');
        $row['errorMode'] = SqlColumn::integer($row, 'errorMode');
        $row['fetchMode'] = SqlColumn::integer($row, 'fetchMode');
        return $row;
    }

    /** @param Data|Common $row
     * @return Failure */
    public static function failure(array $row): array
    {
        if (FixtureJson::boolean($row, 'ok') !== false) { throw new \RuntimeException('Expected failed schedule observation.'); }
        $row['ok'] = false;
        $row['exception'] = SqlColumn::string($row, 'exception');
        $row['sqliteCode'] = FixtureJson::sqliteCode($row, 'sqliteCode');
        return $row;
    }

    /** @return Event */
    public static function event(string $json): array
    {
        $row = JsonRecord::decode($json, 32);
        $row['stage'] = SqlColumn::string($row, 'stage');
        $row['ns'] = SqlColumn::integer($row, 'ns');
        if ($row['stage'] === 'phase-start') { return self::phase($row); }
        if ($row['stage'] === 'attempt-finished') { return self::attempt($row); }
        return $row;
    }

    /** @param Event $row
     * @return Phase */
    public static function phase(array $row): array
    {
        if ($row['stage'] !== 'phase-start') { throw new \RuntimeException('Expected schedule phase event.'); }
        $row['deadline'] = SqlColumn::integer($row, 'deadline');
        return $row;
    }

    /** @param Event $row
     * @return Attempt */
    public static function attempt(array $row): array
    {
        if ($row['stage'] !== 'attempt-finished') { throw new \RuntimeException('Expected schedule attempt event.'); }
        $row['sqliteCode'] = FixtureJson::sqliteCode($row, 'sqliteCode');
        $row['cleanupFailed'] = FixtureJson::boolean($row, 'cleanupFailed');
        $row['statementRetained'] = FixtureJson::boolean($row, 'statementRetained');
        return $row;
    }
}
