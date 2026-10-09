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
            $removed = $file->isDir() && !$file->isLink() ? @rmdir($path) : @unlink($path);
            // A transient SQLite/Windows file can disappear after the directory was enumerated.
            clearstatcache(true, $path);
            if (!$removed && (file_exists($path) || is_link($path))) {
                throw new RuntimeException('Cannot remove test fixture: ' . $path);
            }
        }
        // Release Windows enumeration handles before removing their parent.
        unset($file, $files);
        gc_collect_cycles();
        $removed = @rmdir($this->path);
        if (!$removed && DIRECTORY_SEPARATOR === '\\') {
            // Windows may briefly retain delete-pending handles after child/file
            // closure. Retry only this owned empty-directory removal, never files.
            $deadline = microtime(true) + 0.5;
            do {
                usleep(10000);
                clearstatcache(true, $this->path);
                $removed = !is_dir($this->path) || @rmdir($this->path);
            } while (!$removed && microtime(true) < $deadline);
        }
        if (!$removed) { throw new RuntimeException('Cannot remove test directory: ' . $this->path); }
        $this->closed = true;
    }
}
