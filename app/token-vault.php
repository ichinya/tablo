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
        $cipher = openssl_encrypt($token, 'aes-256-gcm', $this->key(true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Не удалось сохранить токен GitHub.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encrypted): string
    {
        $bytes = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
        if ($bytes === false || strlen($bytes) < 29) {
            throw new \RuntimeException('Сохранённый токен GitHub повреждён. Введите новый токен.');
        }
        $token = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key(false), OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16));
        if ($token === false) {
            throw new \RuntimeException('Не удалось расшифровать токен GitHub. Введите новый токен.');
        }
        return $token;
    }

    public function credentialScope(#[\SensitiveParameter] string $token, bool $initialize = true): string
    {
        // Domain separation: persist neither the token nor an unkeyed digest. The existing
        // private vault key makes this identity stable across processes and external key paths.
        // The repository permits initialization only before encrypted/key-derived state exists.
        // Existing corrupt/unreadable files and dangling links must never become replacement keys.
        $identityKey = hash_hmac('sha256', 'tablo/github-primary-scope/v1',
            $this->key($initialize && !file_exists($this->keyPath) && !is_link($this->keyPath)), true);
        return 'credential:v1:' . hash_hmac('sha256', $token, $identityKey);
    }

    private function key(bool $create): string
    {
        if ($create) {
            if (!is_dir(dirname($this->keyPath))) {
                mkdir(dirname($this->keyPath), 0700, true);
            }
            // c+b does not truncate an existing key; initialize only under the exclusive lock.
            $file = @fopen($this->keyPath, 'c+b');
            if ($file === false || !flock($file, LOCK_EX)) {
                if (is_resource($file)) { fclose($file); }
                throw new \RuntimeException('Не удалось открыть ключ для токенов.');
            }
            @chmod($this->keyPath, 0600);
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
        $file = @fopen($this->keyPath, 'rb');
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
