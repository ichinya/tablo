<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;
use Phalcon\Mvc\View\Simple;
use Phalcon\Mvc\View\Engine\Volt;

final class PageResponse
{
    private readonly Simple $view;
    public function __construct(string $runtimeDirectory)
    {
        $di = new FactoryDefault();
        $this->view = new Simple();
        $this->view->setDI($di);
        $this->view->setViewsDir(dirname(__DIR__) . '/views/');
        $volt = new Volt($this->view, $di);
        $volt->setOptions(['path' => $runtimeDirectory . '/views/', 'autoescape' => true]);
        $this->view->registerEngines(['.volt' => $volt]);
    }
    public function render(string $template, array $data, int $status = 200): Response
    {
        $data += ['csrf' => $_SESSION['csrf'], 'errors' => [], 'authenticated' => (($_SESSION['authenticated_at'] ?? null) !== null), 'notice' => ''];
        return (new Response())->setStatusCode($status)->setContentType('text/html', 'utf-8')
            ->setContent($this->view->render($template, $data));
    }    public function notFound(): Response
    {
        return $this->render('error', ['title' => 'Страница не найдена', 'message' => 'Проверьте адрес или вернитесь к своим сайтам.'], 404);
    }    public function redirect(string $url): Response
    {
        return (new Response())->setStatusCode(303)->setHeader('Location', $url)->setContent('');
    }    public function json(array $data, int $status = 200): Response
    {
        return (new Response())->setStatusCode($status)->setJsonContent($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
