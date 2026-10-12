<?php
declare(strict_types=1);

namespace Tablo;

final class SiteFormPage extends WebPage
{
    public function __construct(private readonly GitTokenRepository $tokens, PageResponse $page) { parent::__construct($page); }
    public function form(#[\SensitiveParameter] array $site, ?int $id = null, array $errors = [], int $status = 200): \Phalcon\Http\Response
    {
        $site = SiteProjection::publicSite($site);
        $site['git_token_id'] ??= '';
        return $this->render('form', ['title' => $id === null ? 'Добавить сайт' : 'Настройки сайта', 'site' => $site,
            'action' => $id === null ? '/sites/new' : '/sites/' . $id . '/edit', 'editing' => $id !== null,
            'errors' => $errors, 'json_operators' => JsonField::OPERATORS,
            'git_tokens' => array_values(array_filter($this->tokens->all(), static fn (#[\SensitiveParameter] $token) => $token['provider'] === 'github'))], $status);
    }
}
