<?php
declare(strict_types=1);

namespace Tablo;

use InvalidArgumentException;

final class IncidentCursor
{
    public static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), from: '+/', to: '-_'), characters: '=');
    }

    public static function decode(string $cursor, ?int $siteId): array
    {
        if ($cursor === '' || strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { self::invalid(); }
        $decoded = base64_decode(strtr($cursor, from: '-_', to: '+/'), strict: true);
        if ($decoded === false) { self::invalid(); }
        try { $value = json_decode($decoded, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($value) || array_keys($value) !== ['v', 'site', 'before', 'ceiling']
            || $value['v'] !== 1 || $value['site'] !== $siteId || !is_int($value['before']) || $value['before'] < 1
            || !is_int($value['ceiling']) || $value['ceiling'] < $value['before'] || self::encode($value) !== $cursor) { self::invalid(); }
        return $value;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid incident query.');
    }
}
