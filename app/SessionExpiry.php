<?php
declare(strict_types=1);

namespace Tablo;

final class SessionExpiry
{
    public static function live(mixed $authenticatedAt, mixed $lastActivityAt, mixed $observedAt, int $now, int $minutes): bool
    {
        if (!is_int($authenticatedAt) || !is_int($lastActivityAt) || !is_int($observedAt)
            || $authenticatedAt < 0 || $lastActivityAt < $authenticatedAt || $observedAt < $lastActivityAt
            || $now < $observedAt || $minutes < 1 || $minutes > 720) { return false; }
        return $now - $authenticatedAt < 43_200 && $now - $lastActivityAt < $minutes * 60;
    }
}
