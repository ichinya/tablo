<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

final class SessionAdmission
{
    private int $epoch;
    /** @param \Closure(): int $clock Internal test dependency; production uses native time. */
    public function __construct(private readonly Auth $auth, private readonly SettingsRepository $settings,
        private readonly PageResponse $page, private readonly SessionResponse $response, private readonly \Closure $clock)
    {
        $this->epoch = ($this->clock)();
        if ($this->epoch < 0) { throw new \RuntimeException('Invalid session clock.'); }
    }

    public function epoch(): int { return $this->epoch; }

    public function admit(string $path): ?Response
    {
        $setup = $this->auth->needsSetup() ? SessionSetupState::Required : SessionSetupState::Complete;
        $this->refresh($setup);
        return WebRouteAdmission::admit($this->page, $path, $setup, $this->response) ?? WebPostAdmission::admit($this->page, $this->response);
    }

    public function refresh(SessionSetupState $setup): void
    {
        SessionAuthentication::refresh($this->auth, $setup);
        if (($_SESSION['authenticated_at'] ?? null) === null) { return; }
        if (!SessionExpiry::live($_SESSION['authenticated_at'], $_SESSION['last_activity_at'] ?? null,
            $_SESSION['session_observed_at'] ?? null, $this->epoch, $this->settings->getIdleTimeout())) {
            WebSession::invalidateAuthentication();
            return;
        }
        $_SESSION['session_observed_at'] = $this->epoch;
    }

    public function activity(): void
    {
        if (($_SESSION['authenticated_at'] ?? null) !== null && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_activity_at'] = $this->epoch;
        }
    }

    public function revisit(): void
    {
        $this->epoch = ($this->clock)();
        if ($this->epoch < 0) { WebSession::invalidateAuthentication(); return; }
        $this->refresh($this->auth->needsSetup() ? SessionSetupState::Required : SessionSetupState::Complete);
    }

    public function refuse(): Response
    {
        return $this->response === SessionResponse::Json
            ? $this->page->json(['error' => 'Authentication required.'], 401) : $this->page->redirect('/login');
    }
}
