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
            WebSession::recordActivity();
            $snapshot = WebSession::release();
            $result = null;
            $notice = '';
                try {
                    $checker = new SiteChecker(new HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'),
                        $this->github->provider(SiteProjection::gitCredentials($site)), $this->vault);
                    $result = $checker->check($site);
                } catch (ValidationException $e) {
                    $notice = implode(' ', $e->errors);
                }
                $refusal = WebSession::resume($snapshot);
                if ($refusal !== null) { return $refusal; }
                $stored = false;
                if ($result !== null) {
                    $stored = $this->sites->storeCheck($site, $result);
                    $notice = $stored ? 'Проверка завершена. Результаты обновлены.' : 'Настройки изменились во время проверки. Запустите её ещё раз.';
                }
                $_SESSION['notice'] = $notice;
                if ($stored) {
                    WebSession::release();
                    $this->notificationDelivery->runOne();
                }
            return $this->redirect('/');
    }

}
