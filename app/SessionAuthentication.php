<?php
declare(strict_types=1);

namespace Tablo;

final class SessionAuthentication
{

    public static function refresh(Auth $auth, SessionSetupState $setup): void
    {
        $needsSetup = $setup === SessionSetupState::Required;
        if ((($_SESSION['authenticated_at'] ?? null) !== null) || (($_SESSION['auth_fingerprint'] ?? null) !== null)) {
            $marker = $_SESSION['auth_fingerprint'] ?? null;
            $current = $auth->credentialFingerprint();
            if ($needsSetup || !(($_SESSION['authenticated_at'] ?? null) !== null) || !is_string($marker)
                || preg_match('/^[a-f0-9]{64}$/D', $marker) !== 1 || $current === null
                || !hash_equals($current, $marker)) {
                WebSession::invalidateAuthentication();
            }
        }
    }
}
