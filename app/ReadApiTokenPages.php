<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class ReadApiTokenPages extends WebPage
{
    public function __construct(private readonly \PDO $db, private readonly ReadApiTokenRepository $tokens, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $app->post('/settings/read-api-token/rotate', new WebAction($this->rotate(...), SessionActivity::DeliberateForm));
        $app->post('/settings/read-api-token/revoke', new WebAction($this->revoke(...), SessionActivity::DeliberateForm));
    }

    private function currentAuthority(): bool
    {
        // The real session lock remains held; re-read committed policy after BEGIN IMMEDIATE.
        return session_status() === PHP_SESSION_ACTIVE && SessionExpiry::live($_SESSION['authenticated_at'] ?? null,
            $_SESSION['last_activity_at'] ?? null, $_SESSION['session_observed_at'] ?? null, time(), (new SettingsRepository($this->db))->getIdleTimeout());
    }

    private function fingerprint(): string
    {
        /** @var mixed $value Session input is untrusted. */
        $value = $_SESSION['auth_fingerprint'] ?? null;
        if (!is_string($value) || !ReadApiToken::validDigest($value)) { throw new \RuntimeException('Administrator authorization expired.'); }
        return $value;
    }

    private function rotate(): Response
    {
        try {
            $secret = $this->tokens->rotate($this->fingerprint(), $this->currentAuthority(...));
            WebSession::recordActivity();
            return $this->issued($secret);
        } catch (\Throwable) { return $this->failure(); }
    }

    private function issued(#[\SensitiveParameter] string $secret): Response
    {
        // This sole direct response is never a flash, redirect parameter or JavaScript value.
        $template = RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/views/read-api-token-issued.volt'));
        $html = str_replace('{{ secret }}', htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8'), $template);
        $response = new Response();
        $response->setContentType('text/html', 'utf-8');
        $response->setHeader('Cache-Control', 'no-store, private');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setContent($html);
        return $response;
    }

    private function revoke(): Response
    {
        try {
            $this->tokens->revoke($this->fingerprint(), $this->currentAuthority(...));
            WebSession::recordActivity();
            return $this->redirect('/settings');
        } catch (\Throwable) { return $this->failure(); }
    }

    private function failure(): Response
    {
        return $this->render('error', ['title' => 'Read API token', 'message' => 'Cannot update read API token. Sign in again and retry.'], 409);
    }
}
