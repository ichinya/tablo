<?php
declare(strict_types=1);

namespace Tablo;

final class VaultKeyFile
{
    private ?string $keyFingerprint = null;
    public function __construct(#[\SensitiveParameter] private readonly string $keyPath, private readonly KeyLocation $location) {}
    public function read(): string
    {
        $key = KeyFileRead::read($this->keyPath, $this->location);
        $fingerprint = hash('sha256', $key);
        if ($this->keyFingerprint !== null && !hash_equals($this->keyFingerprint, $fingerprint)) {
            throw new SharedKeyFailure('Token key custody changed.');
        }
        $this->keyFingerprint ??= $fingerprint;
        return $key;
    }
    public function forCreation(KeyCreation $creation): string
    {
        if ($creation === KeyCreation::Allowed && $this->location !== KeyLocation::External && $this->keyFingerprint === null) { KeyFileInitialization::initialize($this->keyPath); }
        return $this->read();
    }
    public function scopeKey(KeyCreation $creation): string
    {
        $permission = $creation === KeyCreation::Allowed && !file_exists($this->keyPath) && !is_link($this->keyPath) ? KeyCreation::Allowed : KeyCreation::Forbidden;
        return $this->forCreation($permission);
    }
    public function assertAvailable(KeyRequirement $requirement): void
    {
        clearstatcache(true, $this->keyPath);
        if ($this->location === KeyLocation::External || $requirement === KeyRequirement::Existing || $this->keyFingerprint !== null || file_exists($this->keyPath) || is_link($this->keyPath)) { $this->read(); }
    }

}
