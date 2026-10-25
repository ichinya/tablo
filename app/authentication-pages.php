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
        $app->post('/setup', new WebAction($this->setupAction(...)));
        $app->get('/login', new WebAction(static fn () => (($_SESSION['authenticated_at'] ?? null) !== null) ? $web->redirect('/') : $web->render('auth', ['setup' => false, 'title' => 'С возвращением'])));
        $app->post('/login', new WebAction($this->loginAction(...)));
        $app->post('/logout', new WebAction(static function () use ($web) {
            $_SESSION = [];
            session_destroy();
            $name = session_name();
            if ($name === false) { throw new \RuntimeException('Session cookie unavailable.'); }
            setcookie($name, value: '', expires_or_options: ['expires' => time() - 3600, 'path' => '/', 'secure' => session_get_cookie_params()['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            return $web->redirect('/login');
        }));
    }

    private function setupAction(): \Phalcon\Http\Response
    {
        if (!$this->auth->needsSetup()) {
            return $this->notFound();
        }
        try {
            $fingerprint = $this->auth->setupSession($this->input('password'), $this->input('confirmation'));
            WebSession::authenticate($fingerprint);
            return $this->redirect('/');
        } catch (ValidationException $e) {
            return $this->render('auth', ['setup' => true, 'title' => 'Добро пожаловать', 'errors' => $e->errors], 422);
        } catch (PasswordRuntimeFailure $e) {
            return $this->runtimeFailure(true, 'Добро пожаловать', $e);
        }
    }

    private function loginAction(): \Phalcon\Http\Response
    {
        try {
            $password = $this->input('password');
            $fingerprint = $this->auth->loginSession($password, $this->clientAddress->resolve($_SERVER));
            if ($fingerprint === null) {
                throw ValidationException::field(field: 'password', message: 'Неверный пароль.');
            }
            WebSession::authenticate($fingerprint);
            return $this->redirect('/');
        } catch (ValidationException $e) {
            return $this->render('auth', ['setup' => false, 'title' => 'С возвращением', 'errors' => $e->errors], 422);
        } catch (PasswordRuntimeFailure $e) {
            return $this->runtimeFailure(false, 'С возвращением', $e);
        }
    }

    private function runtimeFailure(bool $setup, string $title, PasswordRuntimeFailure $error): \Phalcon\Http\Response
    {
        return $this->render('auth', ['setup' => $setup, 'title' => $title, 'errors' => ['password' => $error->getMessage()]], 503);
    }

}
