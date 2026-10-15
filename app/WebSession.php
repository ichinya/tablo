<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

final class WebSession
{


    private static ?SessionAdmission $admission = null;
    private static SessionActivity $activity = SessionActivity::Automatic;

    public static function authenticate(#[\SensitiveParameter] string $fingerprint): void
    {
        $now = self::current()->epoch();
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if (!session_regenerate_id(true)) { throw new \RuntimeException('Cannot rotate authentication session.'); }
        $_SESSION['authenticated_at'] = $now;
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['session_observed_at'] = $now;
        $_SESSION['auth_fingerprint'] = $fingerprint;
    }
    public static function invalidateAuthentication(): void
    {
        // Clear authority before rotation, including the regeneration-failure boundary.
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if (!session_regenerate_id(true)) { throw new \RuntimeException('Cannot rotate expired session.'); }
    }
    public static function start(string $runtimeDirectory): void { SessionInitialization::start($runtimeDirectory); }
    public static function admit(SessionAdmission $admission, string $path): ?Response
    {
        self::$activity = SessionActivity::Automatic;
        self::$admission = $admission;
        return self::$admission->admit($path);
    }

    public static function classify(SessionActivity $activity): void { self::$activity = $activity; }
    public static function recordActivity(): void
    {
        if (self::$activity !== SessionActivity::Automatic) { self::current()->activity(); }
    }
    public static function release(): SessionSnapshot { return SessionSnapshot::release(); }
    public static function resume(SessionSnapshot $snapshot): ?Response { return SessionResumption::resume(self::current(), $snapshot); }
    private static function current(): SessionAdmission
    { return self::$admission ?? throw new \RuntimeException('Session admission unavailable.'); }
}
