<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

abstract class WebPage
{
    public function __construct(protected readonly PageResponse $page) {}
    protected function render(string $template, array $data, int $status = 200): Response
    { return $this->page->render($template, $data, $status); }
    protected function notFound(): Response
    { return $this->page->notFound(); }
    protected function redirect(string $url): Response
    { return $this->page->redirect($url); }
    protected function json(array $data, int $status = 200): Response
    { return $this->page->json($data, $status); }
    protected function input(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }
}
