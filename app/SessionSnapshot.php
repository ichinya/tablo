<?php
declare(strict_types=1);

namespace Tablo;

/** Identity retained across unlocked work, never a saved session to restore. */
final class SessionSnapshot
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $id,
        #[\SensitiveParameter] public readonly string $csrf,
        #[\SensitiveParameter] public readonly string $fingerprint,
        public readonly int $authenticatedAt,
    ) {}

    public function matches(): bool
    {
        return session_id() === $this->id && ($_SESSION['authenticated_at'] ?? null) === $this->authenticatedAt
            && ($_SESSION['csrf'] ?? null) === $this->csrf && ($_SESSION['auth_fingerprint'] ?? null) === $this->fingerprint;
    }

    public static function release(): self
    {
        $id = session_id();
        /** @var mixed $csrf Native session input is untrusted. */
        $csrf = $_SESSION['csrf'] ?? null;
        /** @var mixed $fingerprint Native session input is untrusted. */
        $fingerprint = $_SESSION['auth_fingerprint'] ?? null;
        /** @var mixed $start Native session input is untrusted. */
        $start = $_SESSION['authenticated_at'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($csrf) || !is_string($fingerprint) || !is_int($start)) {
            throw new \RuntimeException('Session snapshot unavailable.');
        }
        $snapshot = new self($id, $csrf, $fingerprint, $start);
        if (!session_write_close()) { throw new \RuntimeException('Cannot release session lock.'); }
        return $snapshot;
    }
}
