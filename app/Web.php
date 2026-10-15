<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Micro;

final class Web
{
    private readonly PageResponse $page;
    private readonly WebRoutes $routes;
    public function __construct(?HttpClient $githubHttp = null, ?string $runtimeDirectory = null)
    {
        // Validate proxy configuration before opening the selected database, as before.
        new ClientAddress(getenv('TABLO_TRUSTED_PROXIES'));
        $vault = TokenVault::configured();
        $db = Database::connect(vault: $vault);
        $vault ??= TokenVault::forDatabase($db);
        $runtimeDirectory ??= dirname(__DIR__) . '/storage';
        WebSession::start($runtimeDirectory);
        $this->page = new PageResponse($runtimeDirectory);
        $this->routes = new WebRoutes($db, $vault, $this->page, $githubHttp ?? new HttpClient());
    }
    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = $requestedPath ? $requestedPath : '/';
        $refusal = WebSession::admit($this->routes->auth, $this->page, $path);
        if ($refusal !== null) { $refusal->send(); return; }
        $app = new Micro(new FactoryDefault());
        $this->routes->register($app);
        $app->notFound(new WebAction($this->page->notFound(...)));
        $app->handle($path);
    }

}
