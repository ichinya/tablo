<?php
declare(strict_types=1);

namespace Tablo\Tools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class MagoSource
{
    public const DIRECTORIES = ['app', 'bin', 'public', 'tests', 'tools'];

    public static function files(string $root): array
    {
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            $base = $root . '/' . $directory;
            if (!is_dir($base) || is_link($base)) { throw new RuntimeException('Required lint source directory missing.'); }
            $count = 0;
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, flags: FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if ($entry->isLink()) { throw new RuntimeException('Linked lint source is unsupported.'); }
                if (!$entry->isFile() || $entry->getExtension() !== 'php') { continue; }
                $files[] = str_replace('\\', replace: '/', subject: substr($entry->getPathname(), offset: strlen($root) + 1));
                ++$count;
            }
            if ($count === 0) { throw new RuntimeException('Required lint source directory has no PHP files.'); }
        }
        sort($files);
        return $files;
    }

    public static function confirm(array $command, string $root, array $expected): void
    {
        $result = MagoProcess::capture([...$command, 'list-files', '--command', 'linter', '--zero-terminate'], $root);
        if ($result['exit'] !== 0) { throw new RuntimeException('Mago source discovery failed.'); }
        $information = ' INFO Overriding PHP version with 8.2.0.' . PHP_EOL
            . ' INFO Overriding workspace directory with ' . $root . '.' . PHP_EOL;
        if (str_replace("\r\n", replace: "\n", subject: $result['stderr']) !== str_replace("\r\n", replace: "\n", subject: $information)) {
            throw new RuntimeException('Unexpected Mago source discovery diagnostic.');
        }
        $paths = explode("\0", rtrim($result['stdout'], characters: "\0"));
        $paths = array_map(static fn (string $path): string => str_replace('\\', replace: '/', subject: $path), $paths);
        sort($paths);
        if ($paths !== $expected) { throw new RuntimeException('Mago source discovery does not match the full required scope.'); }
    }
}
