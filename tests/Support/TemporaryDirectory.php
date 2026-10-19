<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;

final class TemporaryDirectory
{
    public readonly string $path;
    private bool $closed = false;
    private bool $retained = false;
    private int $processes = 0;

    /** Explicit retention applies only to this object's freshly created synthetic fixture. */
    public function retainFailure(Throwable $primary): void
    {
        $this->retained = true;
        FixtureFailureEvidence::record($this->path, 'primary', $primary);
    }

    public function recordCleanupFailure(Throwable $cleanup): void
    {
        $this->retained = true;
        FixtureFailureEvidence::record($this->path, 'cleanup', $cleanup);
    }

    public function hasFailure(): bool { return $this->retained; }

    /** Preserve an existing primary exception when fixture teardown independently fails. */
    public function cleanup(callable $release): void
    {
        try { $release(); }
        catch (Throwable $cleanup) {
            $primaryExists = $this->retained;
            $this->recordCleanupFailure($cleanup);
            if (!$primaryExists) { throw $cleanup; }
        }
    }

    public function holdProcess(): void { ++$this->processes; }

    public function releaseProcess(): void { --$this->processes; }

    public function __construct(string $prefix = 'tablo-test-', ?string $parentDirectory = null)
    {
        temporary_directory_parent($parentDirectory);
        $this->path = ($parentDirectory ?? sys_get_temp_dir()) . '/' . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($this->path, permissions: 0o700)) {
            throw new RuntimeException('Cannot create temporary test directory');
        }
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        if ($this->retained) { return; }
        if ($this->processes !== 0) {
            $error = new RuntimeException('Cannot release fixture before direct child closure');
            $this->recordCleanupFailure($error);
            throw $error;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) { throw new RuntimeException('Unexpected fixture directory entry'); }
            $path = $file->getPathname();
            if (!($file->isDir() && !$file->isLink() ? FixtureRemoval::directory(\Tablo\Tests\Support\FixtureValue::string($path)) : FixtureRemoval::file(\Tablo\Tests\Support\FixtureValue::string($path)))) {
                throw new RuntimeException('Cannot remove test fixture: ' . $path);
            }
        }
        unset($file, $files);
        gc_collect_cycles();
        if (!FixtureRemoval::directory($this->path)) {
            throw new RuntimeException('Cannot remove test directory: ' . $this->path);
        }
        $this->closed = true;
    }

}

function temporary_directory_parent(?string $directory): void
{
    if ($directory === null) { return; }
    if (!is_dir($directory) || is_link($directory) || basename($directory) !== 'tmp'
        || preg_match('/\Atablo-server-[0-9a-f]{32}\z/D', basename(dirname($directory))) !== 1) {
        throw new RuntimeException('Explicit owned server temporary root refused');
    }
}
