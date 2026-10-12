<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class SettingsPages extends WebPage
{
    public function __construct(private readonly SettingsRepository $installation, private readonly NotificationSettings $notifications, private readonly NotificationOutbox $notificationOutbox, private readonly GitTokenRepository $tokens, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/settings', new WebAction($web->settings(...)));
        $app->post('/settings', new WebAction($web->saveSettings(...)));
        $app->post('/settings/notifications', new WebAction($web->saveNotifications(...)));
    }
    private function settings(array $errors = [], mixed $submitted = null, int $status = 200): Response
    {
        $notificationSite=null;
        if ((($_GET['notification_site_id'] ?? null) !== null) && $_GET['notification_site_id']!=='') {
            try { $notificationSite=IncidentQuery::integer($_GET['notification_site_id'],PHP_INT_MAX); }
            catch (\InvalidArgumentException) { return $this->render('error',['title'=>'Некорректный запрос','message'=>'Проверьте ID сайта.'],400); }
        }
        $notice = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);
        return $this->render('settings', ['title' => 'Настройки', 'tokens' => $this->tokens->all(),
            'providers' => GitProviders::available(), 'notice' => $notice, 'errors' => $errors,
            'notifications' => $this->notifications->get(),
            'notification_status' => $notificationSite===null?$this->notificationOutbox->status():$this->notificationOutbox->statusForSite($notificationSite),
            'notification_site_id' => $notificationSite,
            'notification_status_truncated' => $notificationSite===null && $this->notificationOutbox->statusTruncated(),
            'check_interval_minutes' => $submitted ?? $this->installation->get()], $status);
    }    private function saveSettings(): Response
    {
        try {
            $this->installation->updateInterval($_POST['check_interval_minutes'] ?? null);
            $_SESSION['notice'] = 'Интервал проверок сохранён.';
            return $this->redirect('/settings');
        } catch (ValidationException $error) {
            return $this->settings($error->errors, $this->input('check_interval_minutes'), 422);
        }
    }    private function saveNotifications(): Response
    {
        session_write_close();
        try {
            $this->notifications->update($_POST);
            session_start();
            $_SESSION['notice']='Настройки уведомлений сохранены.';
            return $this->redirect('/settings');
        } catch (\Throwable $error) {
            session_start();
            $errors=$error instanceof ValidationException?$error->errors:['notifications'=>'Не удалось сохранить настройки уведомлений.'];
            return $this->settings($errors,null,422);
        }
    }
}
