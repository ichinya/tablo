<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class TokenPages extends WebPage
{
    public function __construct(private readonly GitTokenRepository $tokens, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/settings/tokens/new', new WebAction(static fn () => $web->tokenForm(['name' => '', 'provider' => 'github'])));
        $app->post('/settings/tokens/new', new WebAction($web->saveToken(...)));
        $app->get('/settings/tokens/{id:[0-9]+}/edit', new WebAction(static function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            return $token ? $web->tokenForm($token, (int) $id) : $web->notFound();
        }));
        $app->post('/settings/tokens/{id:[0-9]+}/edit', new WebAction(static fn ($id) => $web->saveToken((int) $id)));
        $app->get('/settings/tokens/{id:[0-9]+}/delete', new WebAction(static function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            return $token ? $web->render('token-delete', ['title' => 'Удалить токен', 'token' => $token]) : $web->notFound();
        }));
        $app->post('/settings/tokens/{id:[0-9]+}/delete', new WebAction(static function ($id) use ($web) {
            $token = $web->tokens->find((int) $id);
            if (!$token) { return $web->notFound(); }
            try {
                $web->tokens->delete((int) $id);
                $_SESSION['notice'] = 'Токен удалён.';
                return $web->redirect('/settings');
            } catch (ValidationException $e) {
                return $web->render('token-delete', ['title' => 'Удалить токен', 'token' => $token, 'errors' => $e->errors], 422);
            }
        }));
    }
    private function tokenForm(#[\SensitiveParameter] array $token, ?int $id = null, array $errors = [], int $status = 200): Response
    {
        return $this->render('token-form', ['title' => $id === null ? 'Добавить токен' : 'Настройки токена',
            'token' => $token, 'editing' => $id !== null, 'providers' => GitProviders::available(), 'errors' => $errors,
            'action' => $id === null ? '/settings/tokens/new' : '/settings/tokens/' . $id . '/edit'], $status);
    }    private function saveToken(?int $id = null): Response
    {
        if ($id !== null && !$this->tokens->find($id)) { return $this->notFound(); }
        try {
            $this->tokens->save($_POST, $id);
            $_SESSION['notice'] = $id === null ? 'Токен добавлен.' : 'Токен обновлён.';
            return $this->redirect('/settings');
        } catch (\RuntimeException $e) {
            return $this->tokenForm(['name' => $this->input('name'), 'provider' => $this->input('provider'), 'id' => $id], $id,
                $e instanceof ValidationException ? $e->errors : ValidationException::errors(field: 'token', message: 'Не удалось сохранить токен. Проверьте доступ к хранилищу.'), 422);
        }
    }
}
