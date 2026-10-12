<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

final class FixtureRequest
{
    public static function path(string $target): string
    {
        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path)) { throw new \RuntimeException('Fixture request has no valid path'); }
        return $path;
    }
}
