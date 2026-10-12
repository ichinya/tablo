<?php
declare(strict_types=1);

namespace Tablo;

final class RegexCapture
{
    public static function text(#[\SensitiveParameter] array $matches, int|string $key): string
    {
        if (!is_string($matches[$key] ?? null)) { throw new \RuntimeException('Required pattern capture is unavailable.'); }
        return $matches[$key];
    }
}
