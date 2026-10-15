<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Mvc\Micro;

final class WebRoutes
{
    /** @var list<AuthenticationPages|DashboardPage|IncidentPage|SettingsPages|TokenPages|SitePages> */
    private readonly array $pages;
    public readonly Auth $auth;
    public function __construct(\PDO $db, TokenVault $vault, PageResponse $page, HttpClient $http)
    {
        $sites = new SiteRepository($db, $vault);
        $tokens = new GitTokenRepository($db, $vault);
        $github = new GitHubConnection($sites, $http);
        $this->auth = new Auth($db);
        $manual = new ManualCheckPage($sites, $github, $vault, new NotificationDelivery($db, $vault), $page);
        $this->pages = [
            new AuthenticationPages($this->auth, new ClientAddress(getenv('TABLO_TRUSTED_PROXIES')), $page),
            new DashboardPage($sites, $page), new IncidentPage(new IncidentRepository($db), $page),
            new SettingsPages(new SettingsRepository($db), new NotificationSettings($db, $vault), new NotificationOutbox($db), $tokens, $page),
            new TokenPages($tokens, $page), new SitePages($sites, $github, $tokens, $manual, $page),
        ];
    }
    public function register(Micro $app): void
    {
        foreach ($this->pages as $page) { $page->register($app); }
    }

}
