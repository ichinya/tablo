<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryCursor
{
    /** @return array{'ceiling': int, 'from': string, 'id': positive-int, 'site': int, 'time': string, 'to': string, ...} */
    public static function decodeCursor(string $cursor, int $siteId, string $from, string $to): array
    {
        return self::checked(HistoryCursorEncoding::decode($cursor), $siteId, $from, $to);
    }

    /** @return array{ceiling: int, from: string, id: positive-int, site: int, time: string, to: string} */
    private static function checked(mixed $value, int $siteId, string $from, string $to): array
    {
        if (!is_array($value) || array_keys($value) !== ['site', 'from', 'to', 'time', 'id', 'ceiling']) { self::invalidCursor(); }
        // Exact presence/order is established, while scalar values remain untrusted.
        /** @var array{site: mixed, from: mixed, to: mixed, time: mixed, id: mixed, ceiling: mixed} $value */
        if ($value['site'] !== $siteId || $value['from'] !== $from || $value['to'] !== $to
            || !is_int($value['id']) || $value['id'] < 1 || !is_int($value['ceiling']) || $value['ceiling'] < $value['id']) {
            self::invalidCursor();
        }
        try { $time = HistoryTime::canonical($value['time']); }
        catch (\InvalidArgumentException) { self::invalidCursor(); }
        if ($time !== $value['time'] || $time < $from || $time >= $to) { self::invalidCursor(); }
        return $value;
    }    private static function invalidCursor(): never
    {
        throw new \InvalidArgumentException('Invalid history cursor.');
    }
}
