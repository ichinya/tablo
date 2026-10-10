<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;

final class TemporaryDirectory
{
    public readonly string $path;
    private bool $closed = false;

    public function __construct(string $prefix = 'tablo-test-')
    {
        $this->path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($this->path, 0700)) {
            throw new RuntimeException('Cannot create temporary test directory');
        }
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!self::remove($path, $file->isDir() && !$file->isLink())) {
                throw new RuntimeException('Cannot remove test fixture: ' . $path);
            }
        }
        unset($file, $files);
        gc_collect_cycles();
        if (!self::remove($this->path, directory: true)) {
            throw new RuntimeException('Cannot remove test directory: ' . $this->path);
        }
        $this->closed = true;
    }

    private static function remove(string $path, bool $directory): bool
    {
        // On Windows empty directories can remain briefly delete-pending after SQLite closes.
        // Bound retries for every directory, including nested ones; retained files still fail.
        $deadline = microtime(true) + 1;
        do {
            $removed = $directory ? @rmdir($path) : @unlink($path);
            // A transient SQLite/Windows file can disappear after the directory was enumerated.
            clearstatcache(true, $path);
            if ($removed || (!file_exists($path) && !is_link($path))) { return true; }
            if (!$directory) { return false; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        return false;
    }
}
