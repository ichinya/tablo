<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

final class WebSession
{


    public static function authenticate(#[\SensitiveParameter] string $fingerprint): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated_at'] = time();
        $_SESSION['auth_fingerprint'] = $fingerprint;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    public static function invalidateAuthentication(): void
    {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
    }
    public static function start(string $runtimeDirectory): void { SessionInitialization::start($runtimeDirectory); }
    public static function admit(Auth $auth,PageResponse $page,string $path): ?Response
    {
        $setup = $auth->needsSetup() ? SessionSetupState::Required : SessionSetupState::Complete;
        SessionAuthentication::refresh($auth,$setup);
        return WebRouteAdmission::admit($page,$path,$setup) ?? WebPostAdmission::admit($page);
    }
}
