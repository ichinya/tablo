<?php
declare(strict_types=1);

namespace Tablo\Tools;

use RuntimeException;

final class MagoCache
{
    public static function directory(string $root, string $target): void
    {
        $relative = substr($target, offset: strlen($root) + 1);
        if (str_contains($relative, '..') || !str_starts_with($relative, '.ci/mago/')) {
            throw new RuntimeException('Mago cache path is outside the project.');
        }
        $directory = $root;
        foreach (explode('/', $relative) as $component) {
            $directory .= '/' . $component;
            if (!file_exists($directory)) {
                if (!mkdir($directory, permissions: 0o700)) { throw new RuntimeException('Cannot create project Mago cache.'); }
            }
            if (!is_dir($directory) || is_link($directory)
                || str_replace('\\', replace: '/', subject: realpath($directory)) !== str_replace('\\', replace: '/', subject: $directory)) {
                throw new RuntimeException('Linked or invalid Mago cache directory.');
            }
        }
    }
}
