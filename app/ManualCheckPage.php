<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

final class ManualCheckPage extends WebPage
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitHubConnection $github, private readonly TokenVault $vault, private readonly NotificationDelivery $notificationDelivery, PageResponse $page) { parent::__construct($page); }
    public function check(int $id): Response
    {
            $site = $this->sites->find($id);
            if (!$site) {
                return $this->notFound();
            }
            if (!$site['enabled']) {
                $_SESSION['notice'] = 'Проверки этого сайта на паузе.';
                return $this->redirect('/');
            }
            // Release the session lock while network requests are in progress.
                session_write_close();
                try {
                    $checker = new SiteChecker(new HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'),
                        $this->github->provider(SiteProjection::gitCredentials($site)), $this->vault);
                    $stored = $this->sites->storeCheck($site, $checker->check($site));
                    $notice = $stored ? 'Проверка завершена. Результаты обновлены.' : 'Настройки изменились во время проверки. Запустите её ещё раз.';
                    if ($stored) { $this->notificationDelivery->runOne(); }
                } catch (ValidationException $e) {
                    $notice = implode(' ', $e->errors);
                }
                session_start();
                $_SESSION['notice'] = $notice;
            return $this->redirect('/');
    }

}
