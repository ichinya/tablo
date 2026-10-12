<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Mvc\Micro;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class AuthenticationPages extends WebPage
{
    public function __construct(private readonly Auth $auth, private readonly ClientAddress $clientAddress, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/setup', new WebAction(static fn () => $web->auth->needsSetup() ? $web->render('auth', ['setup' => true, 'title' => 'Добро пожаловать']) : $web->notFound()));
        $app->post('/setup', new WebAction(static function () use ($web) {
            if (!$web->auth->needsSetup()) {
                return $web->notFound();
            }
            try {
                $fingerprint = $web->auth->setupSession($web->input('password'), $web->input('confirmation'));
                WebSession::authenticate($fingerprint);
                return $web->redirect('/');
            } catch (ValidationException $e) {
                return $web->render('auth', ['setup' => true, 'title' => 'Добро пожаловать', 'errors' => $e->errors], 422);
            }
        }));
        $app->get('/login', new WebAction(static fn () => (($_SESSION['authenticated_at'] ?? null) !== null) ? $web->redirect('/') : $web->render('auth', ['setup' => false, 'title' => 'С возвращением'])));
        $app->post('/login', new WebAction(static function () use ($web) {
            try {
                $password = $web->input('password');
                $fingerprint = strlen($password) > 72 ? null : $web->auth->loginSession($password, $web->clientAddress->resolve($_SERVER));
                if ($fingerprint === null) {
                    throw ValidationException::field(field: 'password', message: 'Неверный пароль.');
                }
                WebSession::authenticate($fingerprint);
                return $web->redirect('/');
            } catch (ValidationException $e) {
                return $web->render('auth', ['setup' => false, 'title' => 'С возвращением', 'errors' => $e->errors], 422);
            }
        }));
        $app->post('/logout', new WebAction(static function () use ($web) {
            $_SESSION = [];
            session_destroy();
            setcookie(session_name(), value: '', expires_or_options: ['expires' => time() - 3600, 'path' => '/', 'secure' => session_get_cookie_params()['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            return $web->redirect('/login');
        }));
    }

}
