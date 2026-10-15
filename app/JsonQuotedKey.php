<?php
declare(strict_types=1);

namespace Tablo;

final class JsonQuotedKey
{
    public static function read(#[\SensitiveParameter] string $path, int &$offset, int $length): string
    {
                $start = $offset + 1;
                $cursor = $start + 1;
                while ($cursor < $length && $path[$cursor] !== '"') {
                    $cursor += $path[$cursor] === '\\' ? 2 : 1;
                }
                if ($cursor >= $length || ($path[$cursor + 1] ?? '') !== ']') {
                    throw new \InvalidArgumentException('Некорректный ключ в JSON path.');
                }
                try {
                    $key = json_decode(substr($path, $start, $cursor - $start + 1), associative: false, depth: 2, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('Некорректный ключ в JSON path.');
                }
                $offset = $cursor + 2;
        return $key;
    }

}
