<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\JsonRecord;
use Tablo\SqlColumn;

/**
 * @phpstan-type Probe array{leaked: bool, complete: bool, opaque: array<string, int>, redacted: int, previous: bool, typed: bool, reason: ?string, ...}
 * @phpstan-type NativeProbe array{leaked: bool, complete: bool, opaque: array<string, int>, redacted: int, previous: bool, typed: bool, reason: ?string, original_typeerror: bool, original_leaked: bool, handle_released: bool, callback_released: bool, ...}
 * @phpstan-type CallbackProbe array{leaked: bool, complete: bool, opaque: array<string, int>, redacted: int, previous: bool, typed: bool, reason: ?string, callback_boundary: bool, handle_released: bool, callback_released: bool, ...}
 * @phpstan-type SqlProbe array{leaked: bool, complete: bool, opaque: array<string, int>, redacted: int, previous: bool, typed: bool, reason: ?string, sql_boundary: bool, ...}
 * @phpstan-type Native array{NativeProbe, array{external_native_typeerror: bool, external_native_leaked: bool, ...}, CallbackProbe, array{raw_callback_typeerror: bool, raw_callback_leaked: bool, ...}}
 * @phpstan-type Safe array{Probe, Probe, Probe, Probe, Probe, Probe, Probe, Probe, array{raw_control_leaked: bool, ...}, array{private_previous_control_leaked: bool, opaque_complete: bool, ...}, SqlProbe, SqlProbe, array{raw_sql_control_leaked: bool, ...}}
 */
final class EndpointPrivacyRecords
{
    /** @return Probe */
    public static function probe(array $row): array
    {
        $row['leaked'] = FixtureJson::boolean($row, 'leaked');
        $row['complete'] = FixtureJson::boolean($row, 'complete');
        $opaque = JsonRecord::checked(FixtureValue::at($row, 'opaque'));
        $counts = [];
        foreach (array_keys($opaque) as $key) {
            if (!is_string($key)) { throw new \RuntimeException('Invalid fixture trace category.'); }
            $counts[$key] = SqlColumn::integer($opaque, $key);
        }
        $row['opaque'] = $counts;
        $row['redacted'] = SqlColumn::integer($row, 'redacted');
        $row['previous'] = FixtureJson::boolean($row, 'previous');
        $row['typed'] = FixtureJson::boolean($row, 'typed');
        $row['reason'] = SqlColumn::nullableString($row, 'reason');
        return $row;
    }

    /** @return Native */
    public static function native(array $records): array
    {
        self::length($records, 4);
        $first = self::probe(JsonRecord::checked(FixtureValue::at($records, 0)));
        $first['original_typeerror'] = FixtureJson::boolean($first, 'original_typeerror');
        $first['original_leaked'] = FixtureJson::boolean($first, 'original_leaked');
        $first['handle_released'] = FixtureJson::boolean($first, 'handle_released');
        $first['callback_released'] = FixtureJson::boolean($first, 'callback_released');
        $external = JsonRecord::checked(FixtureValue::at($records, 1));
        $external['external_native_typeerror'] = FixtureJson::boolean($external, 'external_native_typeerror');
        $external['external_native_leaked'] = FixtureJson::boolean($external, 'external_native_leaked');
        $callback = self::probe(JsonRecord::checked(FixtureValue::at($records, 2)));
        $callback['callback_boundary'] = FixtureJson::boolean($callback, 'callback_boundary');
        $callback['handle_released'] = FixtureJson::boolean($callback, 'handle_released');
        $callback['callback_released'] = FixtureJson::boolean($callback, 'callback_released');
        $raw = JsonRecord::checked(FixtureValue::at($records, 3));
        $raw['raw_callback_typeerror'] = FixtureJson::boolean($raw, 'raw_callback_typeerror');
        $raw['raw_callback_leaked'] = FixtureJson::boolean($raw, 'raw_callback_leaked');
        return [$first, $external, $callback, $raw];
    }

    /** @return Safe */
    public static function safe(array $records): array
    {
        self::length($records, 13);
        $raw = JsonRecord::checked(FixtureValue::at($records, 8));
        $raw['raw_control_leaked'] = FixtureJson::boolean($raw, 'raw_control_leaked');
        $private = JsonRecord::checked(FixtureValue::at($records, 9));
        $private['private_previous_control_leaked'] = FixtureJson::boolean($private, 'private_previous_control_leaked');
        $private['opaque_complete'] = FixtureJson::boolean($private, 'opaque_complete');
        $sqlA = self::probe(JsonRecord::checked(FixtureValue::at($records, 10)));
        $sqlA['sql_boundary'] = FixtureJson::boolean($sqlA, 'sql_boundary');
        $sqlB = self::probe(JsonRecord::checked(FixtureValue::at($records, 11)));
        $sqlB['sql_boundary'] = FixtureJson::boolean($sqlB, 'sql_boundary');
        $sqlRaw = JsonRecord::checked(FixtureValue::at($records, 12));
        $sqlRaw['raw_sql_control_leaked'] = FixtureJson::boolean($sqlRaw, 'raw_sql_control_leaked');
        return [self::probe(JsonRecord::checked(FixtureValue::at($records, 0))), self::probe(JsonRecord::checked(FixtureValue::at($records, 1))),
            self::probe(JsonRecord::checked(FixtureValue::at($records, 2))), self::probe(JsonRecord::checked(FixtureValue::at($records, 3))),
            self::probe(JsonRecord::checked(FixtureValue::at($records, 4))), self::probe(JsonRecord::checked(FixtureValue::at($records, 5))),
            self::probe(JsonRecord::checked(FixtureValue::at($records, 6))), self::probe(JsonRecord::checked(FixtureValue::at($records, 7))),
            $raw, $private, $sqlA, $sqlB, $sqlRaw];
    }

    private static function length(array $records, int $length): void
    {
        if (!array_is_list($records) || count($records) !== $length) { throw new \RuntimeException('Incomplete fixture privacy records.'); }
    }
}
