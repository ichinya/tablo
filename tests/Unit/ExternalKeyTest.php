<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ExternalKeyTest
{
    private static function refuses(#[\SensitiveParameter] callable $action): string
    {
        try { $action(); } catch (SharedKeyFailure $error) { return $error->getMessage(); }
        throw new \RuntimeException('Expected shared key refusal.');
    }

    #[Test]
    public function invalidExplicitSelectionRefusesBeforeDatabaseRuntimeOrFallback(): void
    {
        $directory = new TemporaryDirectory('tablo-external-refusal-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        try {
            foreach ([0, 1, 31, 33, 64] as $length) { file_put_contents($directory->path . '/' . $length, str_repeat('x', $length)); }
            $paths = [$directory->path . '/missing', $directory->path, 'php://memory', 'file://' . $directory->path . '/32',
                'https://example.com/key', '//example/key', '\\\\example\\key'];
            foreach ([0, 1, 31, 33, 64] as $length) { $paths[] = $directory->path . '/' . $length; }
            if (DIRECTORY_SEPARATOR === '/') { $paths[] = '/dev/null'; }
            foreach ($paths as $path) {
                putenv('TABLO_TOKEN_KEY_FILE=' . $path);
                $message = self::refuses(fn () => Database::connect($directory->path . '/new/db.sqlite'));
                Assert::false(str_contains($message, $directory->path));
                Assert::false(is_dir($directory->path . '/new'));
                Assert::false(is_file($directory->path . '/github-token.key'));
            }
            // Environment APIs cannot carry NUL; the direct external factory must also refuse it.
            putenv('TABLO_TOKEN_KEY_FILE');
            self::refuses(fn () => new TokenVault("bad\0path", true));
            if (DIRECTORY_SEPARATOR === '/') {
                $key = $directory->path . '/permission'; file_put_contents($key, random_bytes(32)); chmod($key, 0000);
                try { self::refuses(fn () => new TokenVault($key, true)); }
                finally { chmod($key, 0600); }
                $fifo = $directory->path . '/fifo';
                if (function_exists('posix_mkfifo')) { posix_mkfifo($fifo, 0600); self::refuses(fn () => new TokenVault($fifo, true)); }
            }
        } finally {
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function exactEmptyAndZeroRelativePathsUseProjectRootAcrossCwd(): void
    {
        $directory = new TemporaryDirectory('tablo-external-selection-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $cwd = getcwd();
        $root = dirname(__DIR__, 2);
        $relative = 'artifacts/key-' . bin2hex(random_bytes(8));
        if (!is_dir($root . '/artifacts')) { mkdir($root . '/artifacts'); }
        try {
            putenv('TABLO_TOKEN_KEY_FILE='); Assert::same(TokenVault::configured(), null);
            putenv('TABLO_TOKEN_KEY_FILE=0'); self::refuses(fn () => TokenVault::configured());
            $raw = random_bytes(32); file_put_contents($root . '/' . $relative, $raw);
            putenv('TABLO_TOKEN_KEY_FILE=' . $relative);
            $vault = TokenVault::configured(); $cipher = $vault->encrypt('synthetic');
            chdir($directory->path);
            Assert::same(TokenVault::configured()->decrypt($cipher), 'synthetic');
            Assert::same(file_get_contents($root . '/' . $relative), $raw);
            if (DIRECTORY_SEPARATOR === '/') {
                symlink($root . '/' . $relative, $directory->path . '/link');
                putenv('TABLO_TOKEN_KEY_FILE=' . $directory->path . '/link');
                Assert::same(TokenVault::configured()->decrypt($cipher), 'synthetic');
            }
        } finally {
            chdir($cwd);
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            if (is_file($root . '/' . $relative)) { unlink($root . '/' . $relative); }
            $directory->close();
        }
    }

}
