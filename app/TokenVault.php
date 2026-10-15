<?php
declare(strict_types=1);

namespace Tablo;

final class TokenVault
{
    private readonly VaultKeyFile $keyFile;
    private readonly string $keyPath;
    private readonly bool $external;

    public function __construct(#[\SensitiveParameter] string $keyPath, bool $external = false)
    {
        $configured = getenv('TABLO_TOKEN_KEY_FILE');
        if ($configured !== false && $configured !== '') { $keyPath = $configured; $external = true; }
        $this->external = $external;
        $this->keyPath = $external ? KeyFilePath::external($keyPath) : $keyPath;
        $this->keyFile = new VaultKeyFile($this->keyPath, $external ? KeyLocation::External : KeyLocation::Local);
        if ($external) { $this->keyFile->read(); }
    }

    public static function configured(): ?self
    {
        $path = getenv('TABLO_TOKEN_KEY_FILE');
        return $path === false || $path === '' ? null : new self($path, true);
    }

    // Policy only: never expose the selected path, key bytes or lifetime fingerprint.
    public function isExternal(): bool { return $this->external; }





    public function encrypt(#[\SensitiveParameter] string $token): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($token, cipher_algo: 'aes-256-gcm', passphrase: $this->keyFile->forCreation(KeyCreation::Allowed), options: OPENSSL_RAW_DATA, iv: $iv, tag: $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Не удалось сохранить токен.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(#[\SensitiveParameter] string $encrypted): string
    {
        $bytes = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, offset: 3), strict: true) : false;
        if ($bytes === false || strlen($bytes) < 29) {
            throw new \RuntimeException('Сохранённый токен повреждён. Введите новый токен.');
        }
        $token = openssl_decrypt(substr($bytes, offset: 28), cipher_algo: 'aes-256-gcm', passphrase: $this->keyFile->read(), options: OPENSSL_RAW_DATA,
            iv: substr($bytes, offset: 0, length: 12), tag: substr($bytes, offset: 12, length: 16));
        if ($token === false) {
            throw new \RuntimeException('Не удалось расшифровать токен. Введите новый токен.');
        }
        return $token;
    }

    public function credentialScope(#[\SensitiveParameter] string $token, KeyCreation $initialize = KeyCreation::Allowed): string
    {
        // Domain separation: persist neither the token nor an unkeyed digest. The existing
        // private vault key makes this identity stable across processes and external key paths.
        // The repository permits initialization only before encrypted/key-derived state exists.
        // Existing corrupt/unreadable files and dangling links must never become replacement keys.
        $identityKey = hash_hmac('sha256', data: 'tablo/github-primary-scope/v1',
            key: $this->keyFile->scopeKey($initialize), binary: true);
        return 'credential:v1:' . hash_hmac('sha256', $token, $identityKey);
    }




    public static function forDatabase(\PDO $db): self { return VaultLocation::forDatabase($db); }
    public function assertAvailable(KeyRequirement $requirement): void { $this->keyFile->assertAvailable($requirement); }
}
