<?php
declare(strict_types=1);

namespace Tablo;

final class JsonPath
{
    /** @return list<array{0: 'key', 1: string}|array{0: 'index', 1: int}> */
    public static function segments(#[\SensitiveParameter] string $path): array
    {
        if ($path === '' || strlen($path) > JsonField::MAX_PATH_BYTES || $path[0] !== '$') {
            throw new \InvalidArgumentException('Укажите JSON path от $, до 512 байт.');
        }
        $segments = [];
        $offset = 1;
        $length = strlen($path);
        $match = [];
        while ($offset < $length) {
            if (preg_match('/\G\.([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $match, flags: 0, offset: $offset)) {
                $segments[] = ['key', RegexCapture::text($match, 1)];
                $offset += strlen(RegexCapture::text($match, 0));
                continue;
            }
            if (preg_match('/\G\[(0|[1-9][0-9]*)\]/', $path, $match, flags: 0, offset: $offset)) {
                $index = filter_var(RegexCapture::text($match, 1), FILTER_VALIDATE_INT);
                if (!is_int($index)) {
                    throw new \InvalidArgumentException('Индекс JSON-массива слишком большой.');
                }
                $segments[] = ['index', $index];
                $offset += strlen(RegexCapture::text($match, 0));
                continue;
            }
            if (substr($path, $offset, length: 2) === '["') {
                $segments[] = ['key', JsonQuotedKey::read($path, $offset, $length)];
                continue;
            }
            throw new \InvalidArgumentException('JSON path: используйте .поле, [индекс] или ["ключ"].');
        }
        return $segments;
    }
}
