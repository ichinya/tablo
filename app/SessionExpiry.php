<?php
declare(strict_types=1);

namespace Tablo;

final class SessionExpiry
{
    public static function expire(): void
    {
        if ((($_SESSION['authenticated_at'] ?? null) !== null) && (!is_int($_SESSION['authenticated_at'])
            || time() - $_SESSION['authenticated_at'] > 43_200)) {
            WebSession::invalidateAuthentication();
        }
    }
}
