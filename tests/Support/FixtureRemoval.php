<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

final class FixtureRemoval
{
    public static function directory(string $path): bool
    {
        // On Windows empty directories can remain briefly delete-pending after SQLite closes.
        // Bound retries for every directory, including nested ones; retained files still fail.
        $deadline = microtime(true) + 1;
        do {
            $removed = \Tablo\NativeWarning::ignore(static function () use (&$path) { return rmdir($path); });
            // A transient SQLite/Windows file can disappear after the directory was enumerated.
            clearstatcache(true, $path);
            if ($removed || (!file_exists($path) && !is_link($path))) { return true; }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        return false;
    }
    public static function file(string $path): bool
    {
        $removed = \Tablo\NativeWarning::ignore(static function () use (&$path) { return unlink($path); });
        clearstatcache(true, $path);
        return $removed || (!file_exists($path) && !is_link($path));
    }
}
