<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

/** Real Testo directory selectors preserve the expanded test union within Lekalo's 64-ID bound. */
final class NativeTestAttachments
{
    public static function selectors(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) {
            if (!is_string($path) || !str_starts_with($path, 'tests/') || !is_file($path)) {
                throw new RuntimeException('Native attachment must name a real test file.');
            }
        }
        if (count($paths) > 64) {
            $paths = self::groupUnitTests($paths);
        }
        if (count($paths) > 64) {
            throw new RuntimeException('Native attachment set exceeds the pinned 64-ID bound.');
        }
        sort($paths);
        return $paths;
    }

    private static function groupUnitTests(array $paths): array
    {
        $selectors = [];
        foreach ($paths as $path) {
            $selectors[] = str_starts_with($path, 'tests/Unit/') ? 'tests/Unit' : $path;
        }
        return array_values(array_unique($selectors));
    }
}
