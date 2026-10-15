<?php
declare(strict_types=1);

namespace Tablo;

final class KeyFileInitialization
{
    public static function initialize(#[\SensitiveParameter] string $path): void
    {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), permissions: 0o700, recursive: true);
            }
            // c+b does not truncate an existing key; initialize only under the exclusive lock.
            $file = \Tablo\NativeWarning::ignore(static fn () => fopen($path, mode: 'c+b'));
            if ($file === false || !flock($file, LOCK_EX)) {
                if (is_resource($file)) { fclose($file); }
                throw new \RuntimeException('Не удалось открыть ключ для токенов.');
            }
            \Tablo\NativeWarning::ignore(static fn () => chmod($path, permissions: 0o600));
            try {
                if (fstat($file)['size'] === 0) {
                    if (fwrite($file, random_bytes(32)) !== 32) {
                        throw new \RuntimeException('Не удалось создать ключ для токенов.');
                    }
                    fflush($file);
                }
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
            }
    }

}
