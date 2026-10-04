<?php
declare(strict_types=1);

namespace Tablo;

final class TokenVault
{
    public function __construct(private readonly string $keyPath) {}

    public static function forDatabase(\PDO $db): self
    {
        $databasePath = $db->query('PRAGMA database_list')->fetch()['file'] ?? '';
        $directory = $databasePath !== '' ? dirname($databasePath) : dirname(__DIR__) . '/storage';
        // Keep the existing key filename so installed credentials remain readable.
        return new self($directory . '/github-token.key');
    }

    public function encrypt(#[\SensitiveParameter] string $token): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($token, cipher_algo: 'aes-256-gcm', passphrase: $this->key(create: true), options: OPENSSL_RAW_DATA, iv: $iv, tag: $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Не удалось сохранить токен GitHub.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encrypted): string
    {
        $bytes = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, offset: 3), strict: true) : false;
        if ($bytes === false || strlen($bytes) < 29) {
            throw new \RuntimeException('Сохранённый токен GitHub повреждён. Введите новый токен.');
        }
        $token = openssl_decrypt(substr($bytes, offset: 28), cipher_algo: 'aes-256-gcm', passphrase: $this->key(create: false), options: OPENSSL_RAW_DATA,
            iv: substr($bytes, offset: 0, length: 12), tag: substr($bytes, offset: 12, length: 16));
        if ($token === false) {
            throw new \RuntimeException('Не удалось расшифровать токен GitHub. Введите новый токен.');
        }
        return $token;
    }

    // An explicit internal mode; callers name the option at the call site.
    // @mago-expect lint:no-boolean-flag-parameter
    private function key(bool $create): string
    {
        if ($create) {
            if (!is_dir(dirname($this->keyPath))) {
                mkdir(dirname($this->keyPath), permissions: 0o700, recursive: true);
            }
            // c+b does not truncate an existing key; initialize only under the exclusive lock.
            // Handle the failure explicitly below; suppress raw filesystem/network warnings.
            // @mago-expect lint:no-error-control-operator
            $file = @fopen($this->keyPath, mode: 'c+b');
            if ($file === false || !flock($file, LOCK_EX)) {
                if (is_resource($file)) { fclose($file); }
                throw new \RuntimeException('Не удалось открыть ключ для токенов.');
            }
            // Best-effort POSIX permissions; Windows does not support the same file modes.
            // @mago-expect lint:no-error-control-operator
            @chmod($this->keyPath, permissions: 0o600);
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
        // Handle the failure explicitly below; suppress raw filesystem/network warnings.
        // @mago-expect lint:no-error-control-operator
        $file = @fopen($this->keyPath, mode: 'rb');
        if ($file === false) {
            throw new \RuntimeException('Ключ шифрования токенов недоступен. Восстановите его из резервной копии.');
        }
        flock($file, LOCK_SH);
        try {
            $key = stream_get_contents($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        if (!is_string($key) || strlen($key) !== 32) {
            throw new \RuntimeException('Некорректный ключ шифрования токенов.');
        }
        return $key;
    }
}
