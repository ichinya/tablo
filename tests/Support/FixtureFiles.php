<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class FixtureFiles
{
    /** Files may change between independent child observations. */
    public static function exists(string $path): bool { return is_file($path); }
    /** @return list<string> */
    public static function matches(string $pattern): array
    {
        $matches = glob($pattern);
        if ($matches === false) { throw new \RuntimeException('Cannot discover fixture files'); }
        return $matches;
    }

    public static function first(string $pattern): string
    {
        return self::matches($pattern)[0] ?? throw new \RuntimeException('Required fixture file is unavailable');
    }

    /** @return list<string> */
    public static function lines(string $path, int $flags = 0): array
    {
        $lines = file($path, $flags);
        if ($lines === false) { throw new \RuntimeException('Cannot read fixture lines'); }
        return array_values($lines);
    }
}
