<?php
declare(strict_types=1);

namespace Tablo;

final class KeyFileRead
{
    public static function read(#[\SensitiveParameter] string $path, KeyLocation $location): string
    {
        if ($location === KeyLocation::External) {
            clearstatcache(true, $path);
            if (!is_file($path) || !is_readable($path)) {
                throw new SharedKeyFailure('External token key file is unavailable or invalid.');
            }
        }
        $file = \Tablo\NativeWarning::ignore(static fn () => fopen($path, mode: 'rb'));
        if ($file === false) {
            throw new SharedKeyFailure('Ключ шифрования токенов недоступен. Восстановите его из резервной копии.');
        }
        try {
            $stat = fstat($file);
            if ($location === KeyLocation::External && (!is_array($stat) || ($stat['mode'] & 0o170_000) !== 0o100_000)) {
                throw new SharedKeyFailure('External token key file is unavailable or invalid.');
            }
            if (!flock($file, LOCK_SH)) { throw new SharedKeyFailure('Ключ шифрования токенов недоступен.'); }
            $key = \Tablo\NativeWarning::ignore(static function () use (&$file) { return stream_get_contents($file, length: 33); });
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        if (!is_string($key) || strlen($key) !== 32) {
            throw new SharedKeyFailure('Некорректный ключ шифрования токенов.');
        }
        return $key;
    }

}
