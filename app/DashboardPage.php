<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class DashboardPage extends WebPage
{
    public function __construct(private readonly SiteRepository $sites, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/', new WebAction($web->dashboard(...)));
    }
    private function dashboard(): Response
    {
        $sites = array_map([Presenter::class, 'site'], $this->sites->all());
        $stats = ['total' => count($sites), 'online' => 0, 'attention' => 0, 'paused' => 0];
        foreach ($sites as $site) {
            if (!$site['enabled']) {
                ++$stats['paused'];
            }
            if ($site['enabled'] && $site['online'] === 1) {
                ++$stats['online'];
            }
            if ($site['attention']) {
                ++$stats['attention'];
            }
        }
        $notice = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);
        return $this->render('dashboard', ['title' => 'Обзор сайтов', 'sites' => $sites, 'stats' => $stats, 'notice' => $notice]);
    }
}
