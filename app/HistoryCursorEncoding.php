<?php
declare(strict_types=1);

namespace Tablo;

final class HistoryCursorEncoding
{
    public static function decode(string $cursor): mixed
    {
        if ($cursor === '' || strlen($cursor) > 1024 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { throw new \InvalidArgumentException('Invalid history cursor.'); }
        $decoded = base64_decode(strtr($cursor, from: '-_', to: '+/'), strict: true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), from: '+/', to: '-_'), characters: '=') !== $cursor) { throw new \InvalidArgumentException('Invalid history cursor.'); }
        try { return json_decode($decoded, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \InvalidArgumentException('Invalid history cursor.'); }
    }
}
