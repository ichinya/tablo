<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use ErrorException;
use RuntimeException;
use Tablo\Database;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabasePathTest
{
    #[Test]
    public function rejectsMemoryUrisWithoutFilesystemWarningOrDirectoryCreation(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-uri-');
        $workingDirectory = getcwd();
        chdir($directory->path);
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            foreach (['file::memory:?cache=shared', 'file:///:memory:?mode=memory&cache=shared'] as $uri) {
                try {
                    Database::connect($uri);
                    Assert::true(false);
                } catch (RuntimeException $error) {
                    Assert::same($error->getMessage(), 'SQLite WAL is required for file databases; use writable local storage.');
                }
            }
            Assert::same(scandir($directory->path), ['.', '..']);
        } finally {
            restore_error_handler();
            chdir($workingDirectory);
            $directory->close();
        }
    }

    #[Test]
    public function createsNestedWindowsDrivePathOrLocalColonPath(): void
    {
        $directory = new TemporaryDirectory('tablo-wal-path-');
        try {
            // Windows exercises an actual drive letter and backslashes; POSIX permits colons.
            $path = PHP_OS_FAMILY === 'Windows'
                ? str_replace(search: '/', replace: '\\', subject: $directory->path) . '\\nested\\test.sqlite'
                : $directory->path . '/local:directory/test.sqlite';
            $db = Database::connect($path);
            Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'wal');
            Assert::true(is_file($path));
        } finally {
            unset($db);
            $directory->close();
        }
        Assert::false(is_dir($directory->path));
    }
}
