<?php
declare(strict_types=1);

namespace Tablo;

final class SessionExpiry
{
    public static function expire(): void
    {
        if (self::expired($_SESSION['authenticated_at'] ?? null)) {
            WebSession::invalidateAuthentication();
        }
    }

    private static function expired(mixed $authenticatedAt): bool
    {
        return $authenticatedAt !== null && (!is_int($authenticatedAt) || time() - $authenticatedAt > 43_200);
    }
}
