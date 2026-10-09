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
        if (!rmdir($this->path)) { throw new RuntimeException('Cannot remove test directory: ' . $this->path); }
        $this->closed = true;
    }
}
