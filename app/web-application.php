<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\View\Simple;
use Phalcon\Mvc\View\Engine\Volt;

final class Web
{
    private readonly SiteRepository $sites;
    private readonly GitTokenRepository $tokens;
    private readonly SettingsRepository $installation;
    private readonly Auth $auth;
    private readonly ClientAddress $clientAddress;
    private readonly GitHubConnection $github;
    private readonly Simple $view;

    public function __construct(?HttpClient $githubHttp = null, ?string $runtimeDirectory = null)
    {
        $this->clientAddress = new ClientAddress(getenv('TABLO_TRUSTED_PROXIES'));
        $vault = TokenVault::configured();
        $db = Database::connect(vault: $vault);
        $vault ??= TokenVault::forDatabase($db);
        $root = dirname(__DIR__);
        $runtimeDirectory ??= $root . '/storage';
        foreach (['sessions', 'views'] as $dir) {
            if (!is_dir($runtimeDirectory . '/' . $dir)) {
                mkdir($runtimeDirectory . '/' . $dir, 0700, true);
            }
        }
        session_save_path($runtimeDirectory . '/sessions');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('tablo_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
            'secure' => getenv('TABLO_COOKIE_SECURE') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (isset($_SESSION['authenticated_at']) && (!is_int($_SESSION['authenticated_at'])
            || time() - $_SESSION['authenticated_at'] > 43200)) {
            $this->invalidateAuthentication();
        }
        $this->sites = new SiteRepository($db, $vault);
        $this->tokens = new GitTokenRepository($db, $vault);
        $this->installation = new SettingsRepository($db);
        $this->auth = new Auth($db);
        $this->github = new GitHubConnection($this->sites, $githubHttp ?? new HttpClient());
        $di = new FactoryDefault();
        $this->view = new Simple();
        $this->view->setDI($di);
        $this->view->setViewsDir($root . '/views/');
        $this->view->registerEngines(['.volt' => function ($view) use ($di, $runtimeDirectory) {
            $volt = new Volt($view, $di);
            $volt->setOptions(['path' => $runtimeDirectory . '/views/', 'autoescape' => true]);
            return $volt;
        }]);
    }

    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $needsSetup = $this->auth->needsSetup();
        if (isset($_SESSION['authenticated_at']) || isset($_SESSION['auth_fingerprint'])) {
            $marker = $_SESSION['auth_fingerprint'] ?? null;
            $current = $this->auth->credentialFingerprint();
            if ($needsSetup || !isset($_SESSION['authenticated_at']) || !is_string($marker)
                || preg_match('/^[a-f0-9]{64}$/D', $marker) !== 1 || $current === null
                || !hash_equals($current, $marker)) {
                $this->invalidateAuthentication();
            }
        }
        if (!$needsSetup && $path === '/setup') {
            $this->notFound()->send();
            return;
        }
        $public = in_array($path, ['/setup', '/login'], true);
        if ($needsSetup && $path !== '/setup') {
            $this->redirect('/setup')->send();
            return;
        }
        if (!$public && !isset($_SESSION['authenticated_at'])) {
            $this->redirect('/login')->send();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrf = $_POST['_csrf'] ?? null;
            if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                $this->render('error', ['title' => 'Слишком большой запрос', 'message' => 'Данные формы превышают 16 КБ.'], 413)->send();
                return;
            }
            if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
                $this->render('error', ['title' => 'Форма устарела', 'message' => 'Обновите страницу и повторите действие.'], 419)->send();
                return;
            }
        }
        $web = $this;
        $app = new Micro(new FactoryDefault());
        $app->get('/setup', fn () => $web->auth->needsSetup() ? $web->render('auth', ['setup' => true, 'title' => 'Добро пожаловать']) : $web->notFound());
        $app->post('/setup', function () use ($web) {
            if (!$web->auth->needsSetup()) {
                return $web->notFound();
            }
            try {
                $fingerprint = $web->auth->setupSession($web->input('password'), $web->input('confirmation'));
                $web->authenticate($fingerprint);
                return $web->redirect('/');
            } catch (ValidationException $e) {
                return $web->render('auth', ['setup' => true, 'title' => 'Добро пожаловать', 'errors' => $e->errors], 422);
            }
        });
        $app->get('/login', fn () => isset($_SESSION['authenticated_at']) ? $web->redirect('/') : $web->render('auth', ['setup' => false, 'title' => 'С возвращением']));
        $app->post('/login', function () use ($web) {
            try {
                $password = $web->input('password');
                $fingerprint = strlen($password) > 72 ? null : $web->auth->loginSession($password, $web->clientAddress->resolve($_SERVER));
                if ($fingerprint === null) {
                    throw new ValidationException(['password' => 'Неверный пароль.']);
                }
                $web->authenticate($fingerprint);
                return $web->redirect('/');
            } catch (ValidationException $e) {
                return $web->render('auth', ['setup' => false, 'title' => 'С возвращением', 'errors' => $e->errors], 422);
            }
        });
        $app->post('/logout', function () use ($web) {
            $_SESSION = [];
            session_destroy();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => session_get_cookie_params()['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            return $web->redirect('/login');
        });
        $app->get('/', fn () => $web->dashboard());
        $app->get('/settings', fn () => $web->settings());
        $app->post('/settings', fn () => $web->saveSettings());
        $app->get('/settings/tokens/new', fn () => $web->tokenForm(['name' => '', 'provider' => 'github']));
        $app->post('/settings/tokens/new', fn () => $web->saveToken());
        $app->get('/settings/tokens/{id:[0-9]+}/edit', function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            return $token ? $web->tokenForm($token, (int) $id) : $web->notFound();
        });
        $app->post('/settings/tokens/{id:[0-9]+}/edit', fn ($id) => $web->saveToken((int) $id));
        $app->get('/settings/tokens/{id:[0-9]+}/delete', function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            return $token ? $web->render('token-delete', ['title' => 'Удалить токен', 'token' => $token]) : $web->notFound();
        });
        $app->post('/settings/tokens/{id:[0-9]+}/delete', function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            if (!$token) { return $web->notFound(); }
            try {
                $web->tokens->delete((int) $id);
                $_SESSION['notice'] = 'Токен удалён.';
                return $web->redirect('/settings');
            } catch (ValidationException $e) {
                return $web->render('token-delete', ['title' => 'Удалить токен', 'token' => $token, 'errors' => $e->errors], 422);
            }
        });
        $app->get('/sites/new', fn () => $web->form(SiteRepository::defaults()));
        $app->post('/sites/new', fn () => $web->save());
        $app->post('/sites/branches', fn () => $web->branches());
        $app->get('/sites/{id:[0-9]+}/edit', function ($id) use ($web) {
            $site = $web->sites->find((int) $id);
            return $site ? $web->form($site, (int) $id) : $web->notFound();
        });
        $app->post('/sites/{id:[0-9]+}/edit', fn ($id) => $web->save((int) $id));
        $app->get('/sites/{id:[0-9]+}/delete', function ($id) use ($web) {
            $site = $web->sites->find((int) $id);
            return $site ? $web->render('delete', ['title' => 'Удалить сайт', 'site' => $site]) : $web->notFound();
        });
        $app->post('/sites/{id:[0-9]+}/delete', function ($id) use ($web) {
            if (!$web->sites->find((int) $id)) {
                return $web->notFound();
            }
            $web->sites->delete((int) $id);
            $_SESSION['notice'] = 'Сайт удалён.';
            return $web->redirect('/');
        });
        $app->post('/sites/{id:[0-9]+}/check', function ($id) use ($web) {
            $site = $web->sites->find((int) $id);
            if (!$site) {
                return $web->notFound();
            }
            if (!$site['enabled']) {
                $_SESSION['notice'] = 'Проверки этого сайта на паузе.';
            } else {
                // Release the session lock while network requests are in progress.
                session_write_close();
                try {
                    $checker = new SiteChecker(new HttpClient(getenv('TABLO_ALLOW_PRIVATE_NETWORK') === '1'), $web->github->provider($site));
                    $stored = $web->sites->storeCheck($site, $checker->check($site));
                    $notice = $stored ? 'Проверка завершена. Результаты обновлены.' : 'Настройки изменились во время проверки. Запустите её ещё раз.';
                } catch (ValidationException $e) {
                    $notice = implode(' ', $e->errors);
                }
                session_start();
                $_SESSION['notice'] = $notice;
            }
            return $web->redirect('/');
        });
        $app->notFound(fn () => $web->notFound());
        $app->handle($path);
    }

    private function authenticate(#[\SensitiveParameter] string $fingerprint): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated_at'] = time();
        $_SESSION['auth_fingerprint'] = $fingerprint;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    private function invalidateAuthentication(): void
    {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
    }

    private function input(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }

    private function save(?int $id = null): Response
    {
        $existing = $id === null ? null : $this->sites->find($id);
        if ($id !== null && !$existing) {
            return $this->notFound();
        }
        try {
            $this->github->validate($_POST, $existing);
            $this->sites->save($_POST, $id);
            $_SESSION['notice'] = $id === null ? 'Сайт добавлен. Запустите первую проверку.' : 'Настройки сохранены. Запустите новую проверку.';
            return $this->redirect('/');
        } catch (ValidationException | \RuntimeException $e) {
            $data = SiteRepository::defaults();
            foreach ($data as $key => $value) {
                if (is_string($_POST[$key] ?? null)) {
                    $data[$key] = $_POST[$key];
                }
            }
            $data['enabled'] = isset($_POST['enabled']) && is_string($_POST['enabled']) ? 1 : 0;
            $data['id'] = $id;
            $data['has_github_token'] = !empty($existing['github_token']);
            $data['git_token_id'] = is_string($_POST['git_token_id'] ?? null) ? $_POST['git_token_id'] : ($existing['git_token_id'] ?? '');
            return $this->form($data, $id, $e instanceof ValidationException ? $e->errors : ['github_token' => $e->getMessage()], 422);
        }
    }

    private function form(array $site, ?int $id = null, array $errors = [], int $status = 200): Response
    {
        $site['has_github_token'] = $site['has_github_token'] ?? !empty($site['github_token']);
        $site['git_token_id'] ??= '';
        unset($site['github_token'], $site['selected_token_snapshot']);
        return $this->render('form', ['title' => $id === null ? 'Добавить сайт' : 'Настройки сайта', 'site' => $site,
            'action' => $id === null ? '/sites/new' : '/sites/' . $id . '/edit', 'editing' => $id !== null,
            'errors' => $errors, 'json_operators' => JsonField::OPERATORS,
            'git_tokens' => array_values(array_filter($this->tokens->all(), fn ($token) => $token['provider'] === 'github'))], $status);
    }

    private function settings(array $errors = [], mixed $submitted = null, int $status = 200): Response
    {
        $notice = $_SESSION['notice'] ?? '';
        unset($_SESSION['notice']);
        return $this->render('settings', ['title' => 'Настройки', 'tokens' => $this->tokens->all(),
            'providers' => GitProviders::available(), 'notice' => $notice, 'errors' => $errors,
            'check_interval_minutes' => $submitted ?? $this->installation->get()], $status);
    }

    private function saveSettings(): Response
    {
        try {
            $this->installation->updateInterval($_POST['check_interval_minutes'] ?? null);
            $_SESSION['notice'] = 'Интервал проверок сохранён.';
            return $this->redirect('/settings');
        } catch (ValidationException $error) {
            return $this->settings($error->errors, $this->input('check_interval_minutes'), 422);
        }
    }

    private function tokenForm(array $token, ?int $id = null, array $errors = [], int $status = 200): Response
    {
        return $this->render('token-form', ['title' => $id === null ? 'Добавить токен' : 'Настройки токена',
            'token' => $token, 'editing' => $id !== null, 'providers' => GitProviders::available(), 'errors' => $errors,
            'action' => $id === null ? '/settings/tokens/new' : '/settings/tokens/' . $id . '/edit'], $status);
    }

    private function saveToken(?int $id = null): Response
    {
        if ($id !== null && !$this->tokens->find($id)) { return $this->notFound(); }
        try {
            $this->tokens->save($_POST, $id);
            $_SESSION['notice'] = $id === null ? 'Токен добавлен.' : 'Токен обновлён.';
            return $this->redirect('/settings');
        } catch (\RuntimeException $e) {
            return $this->tokenForm(['name' => $this->input('name'), 'provider' => $this->input('provider'), 'id' => $id], $id,
                $e instanceof ValidationException ? $e->errors : ['token' => 'Не удалось сохранить токен. Проверьте доступ к хранилищу.'], 422);
        }
    }

    private function branches(): Response
    {
        $id = $_POST['site_id'] ?? '';
        $site = null;
        if ($id !== '') {
            if (!is_string($id) || !ctype_digit($id) || !($site = $this->sites->find((int) $id))) {
                return $this->json(['error' => 'Сайт не найден.'], 404);
            }
        }
        // Credentials travel only in this authenticated, CSRF-protected POST.
        session_write_close();
        try {
            return $this->json($this->github->branches($_POST, $site));
        } catch (ValidationException $e) {
            return $this->json(['error' => implode(' ', $e->errors)], 422);
        }
    }

    private function json(array $data, int $status = 200): Response
    {
        return (new Response())->setStatusCode($status)->setJsonContent($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function dashboard(): Response
    {
        $sites = array_map([Presenter::class, 'site'], $this->sites->all());
        $stats = ['total' => count($sites), 'online' => 0, 'attention' => 0, 'paused' => 0];
        foreach ($sites as $site) {
            if (!$site['enabled']) {
                ++$stats['paused'];
            } elseif ($site['online'] === 1) {
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

    private function notFound(): Response
    {
        return $this->render('error', ['title' => 'Страница не найдена', 'message' => 'Проверьте адрес или вернитесь к своим сайтам.'], 404);
    }

    private function redirect(string $url): Response
    {
        return (new Response())->setStatusCode(303)->setHeader('Location', $url)->setContent('');
    }

    private function render(string $template, array $data, int $status = 200): Response
    {
        $data += ['csrf' => $_SESSION['csrf'], 'errors' => [], 'authenticated' => isset($_SESSION['authenticated_at']), 'notice' => ''];
        return (new Response())->setStatusCode($status)->setContentType('text/html', 'utf-8')
            ->setContent($this->view->render($template, $data));
    }
}
