<?php
declare(strict_types=1);

namespace Tablo;

final class WebRouteAdmission
{
    public static function admit(PageResponse $page,string $path, SessionSetupState $setup, SessionResponse $response = SessionResponse::Html): ?\Phalcon\Http\Response
    {
        $needsSetup = $setup === SessionSetupState::Required;
        if (!$needsSetup && $path === '/setup') {
            return $page->notFound();
        }
        $public = in_array($path, ['/setup', '/login'], strict: true);
        if ($needsSetup && $path !== '/setup') {
            return $page->redirect('/setup');
        }
        if (!$public && !(($_SESSION['authenticated_at'] ?? null) !== null)) {
            return $response === SessionResponse::Json ? $page->json(['error' => 'Authentication required.'], 401) : $page->redirect('/login');
        }
        return null;
    }
}
