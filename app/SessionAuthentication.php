<?php
declare(strict_types=1);

namespace Tablo;

final class SessionAuthentication
{

    public static function refresh(Auth $auth, SessionSetupState $setup): void
    {
        if (array_intersect(['authenticated_at', 'last_activity_at', 'session_observed_at', 'auth_fingerprint'], array_keys($_SESSION)) !== []) {
            self::validate($auth, $setup, $_SESSION['auth_fingerprint'] ?? null);
        }
    }

    private static function validate(Auth $auth, SessionSetupState $setup, mixed $marker): void
    {
            $current = $auth->credentialFingerprint();
            if ($setup === SessionSetupState::Required || !(($_SESSION['authenticated_at'] ?? null) !== null) || !is_string($marker)
                || preg_match('/^[a-f0-9]{64}$/D', $marker) !== 1 || $current === null
                || !hash_equals($current, $marker)) {
                WebSession::invalidateAuthentication();
            }
    }
}
