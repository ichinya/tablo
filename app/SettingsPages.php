<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class SettingsPages extends WebPage
{
    private ?ReadApiTokenRepository $readApiTokens = null;
    private ?SelfUpdateRepository $selfUpdate = null;
    public function withSelfUpdate(SelfUpdateRepository $repository): self { $this->selfUpdate = $repository; return $this; }
    public function __construct(private readonly SettingsRepository $installation, private readonly NotificationSettings $notifications, private readonly NotificationOutbox $notificationOutbox, private readonly GitTokenRepository $tokens, PageResponse $page) { parent::__construct($page); }
    public function withReadApiTokens(ReadApiTokenRepository $tokens): self { $this->readApiTokens = $tokens; return $this; }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/settings', new WebAction($web->settings(...), SessionActivity::HumanPage));
        $app->post('/settings', new WebAction($web->saveSettings(...), SessionActivity::DeliberateForm));
        $app->post('/settings/session-timeout', new WebAction($web->saveSessionTimeout(...), SessionActivity::DeliberateForm));
        $app->post('/settings/self-update', new WebAction($web->saveSelfUpdate(...), SessionActivity::DeliberateForm));
        $app->post('/settings/notifications', new WebAction($web->saveNotifications(...), SessionActivity::DeliberateForm));
    }
    private function settings(array $errors = [], mixed $submitted = null, int $status = 200, ?string $idleSubmitted = null): Response
    {
        $notificationSite=null;
        if ((($_GET['notification_site_id'] ?? null) !== null) && $_GET['notification_site_id']!=='') {
            try { $notificationSite=IncidentQuery::integer($_GET['notification_site_id'],PHP_INT_MAX); }
            catch (\InvalidArgumentException) { return $this->render('error',['title'=>'Некорректный запрос','message'=>'Проверьте ID сайта.'],400); }
        }
        $data = ['notice' => $_SESSION['notice'] ?? ''];
        unset($_SESSION['notice']);
        return $this->render('settings', $data + ['title' => 'Настройки', 'tokens' => $this->tokens->all(),
            'providers' => GitProviders::available(), 'errors' => $errors, 'read_api_token' => read_api_settings_metadata($this->readApiTokens),
            'notifications' => $this->notifications->get(),
            'notification_status' => $notificationSite===null?$this->notificationOutbox->status():$this->notificationOutbox->statusForSite($notificationSite),
            'notification_site_id' => $notificationSite,
            'notification_status_truncated' => $notificationSite===null && $this->notificationOutbox->statusTruncated(),
            'check_interval_minutes' => $submitted ?? $this->installation->get(),
            'session_idle_timeout_minutes' => $idleSubmitted ?? $this->installation->getIdleTimeout()], $status);
    }    private function saveSettings(): Response
    {
        WebSession::recordActivity();
        try {
            $this->installation->updateInterval($_POST['check_interval_minutes'] ?? null);
            $_SESSION['notice'] = 'Интервал проверок сохранён.';
            return $this->redirect('/settings');
        } catch (ValidationException $error) {
            return $this->settings($error->errors, $this->input('check_interval_minutes'), 422);
        }
    }

    private function saveSessionTimeout(): Response
    {
        WebSession::recordActivity();
        try {
            $this->installation->updateIdleTimeout($_POST['session_idle_timeout_minutes'] ?? null);
            $_SESSION['notice'] = 'Таймаут неактивности сохранён.';
            return $this->redirect('/settings');
        } catch (ValidationException $error) {
            return $this->settings($error->errors, null, 422, $this->input('session_idle_timeout_minutes'));
        }
    }

    private function saveSelfUpdate(): Response
    { return settings_save_self_update($this->selfUpdate, $this->settings(...), $this->page); }

    private function saveNotifications(): Response
    {
        WebSession::recordActivity();
        try {
            $this->notifications->update($_POST);
            $_SESSION['notice']='Настройки уведомлений сохранены.';
            return $this->redirect('/settings');
        } catch (\Throwable $error) {
            $errors=$error instanceof ValidationException?$error->errors:['notifications'=>'Не удалось сохранить настройки уведомлений.'];
            return $this->settings($errors,null,422);
        }
    }
}

/** @param \Closure(array $errors, mixed $submitted, int $status): Response $settings */
function settings_save_self_update(?SelfUpdateRepository $repository, \Closure $settings, PageResponse $page): Response
{
        WebSession::recordActivity();
        try {
            if ($repository === null) { throw new \RuntimeException('Self-update settings unavailable.'); }
            $repository->configure($_POST['self_update_enabled'] ?? null, $_POST['self_update_interval_minutes'] ?? null);
            $_SESSION['notice'] = 'Настройки проверки обновлений сохранены.';
            return $page->redirect('/settings');
        } catch (ValidationException $error) { return $settings($error->errors, submitted: null, status: 422); }
    }
