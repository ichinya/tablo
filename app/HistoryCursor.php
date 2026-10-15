<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryCursor
{
    public static function decodeCursor(string $cursor, int $siteId, string $from, string $to): array
    {
        $value = HistoryCursorEncoding::decode($cursor);
        if (!is_array($value) || array_keys($value) !== ['site', 'from', 'to', 'time', 'id', 'ceiling']
            || $value['site'] !== $siteId || $value['from'] !== $from || $value['to'] !== $to
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
