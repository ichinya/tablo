<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;

final class IncidentCursor
{
    public static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(JsonEncoding::encode($value)), from: '+/', to: '-_'), characters: '=');
    }

    /** @return array{'before': positive-int, 'ceiling': int, 'v': int, ...} */
    public static function decode(string $cursor, ?int $siteId): array
    {
        if ($cursor === '' || strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { self::invalid(); }
        $decoded = base64_decode(strtr($cursor, from: '-_', to: '+/'), strict: true);
        if ($decoded === false) { self::invalid(); }
        try { return self::checked(json_decode($decoded, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR), $cursor, $siteId); }
        catch (\JsonException) { self::invalid(); }
    }

    /** @return array{v: int, site: ?int, before: positive-int, ceiling: int} */
    private static function checked(mixed $value, string $cursor, ?int $siteId): array
    {
        if (!is_array($value) || array_keys($value) !== ['v', 'site', 'before', 'ceiling']) { self::invalid(); }
        // Exact key presence is established; values remain untrusted until the scalar checks below.
        /** @var array{v: mixed, site: mixed, before: mixed, ceiling: mixed} $value */
        if ($value['v'] !== 1 || $value['site'] !== $siteId || !is_int($value['before']) || $value['before'] < 1
            || !is_int($value['ceiling']) || $value['ceiling'] < $value['before'] || self::encode($value) !== $cursor) { self::invalid(); }
        // Exact keys, scope, scalar types, ranges and canonical encoding were checked above.
        /** @var array{v: int, site: ?int, before: positive-int, ceiling: int} $value */
        return $value;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid incident query.');
    }
}
