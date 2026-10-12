<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SitePages extends WebPage
{
    public function __construct(private readonly SiteRepository $sites, private readonly GitHubConnection $github, private readonly GitTokenRepository $tokens, private readonly ManualCheckPage $manual, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/sites/new', new WebAction(static fn () => (new SiteFormPage($web->tokens,$web->page))->form(SiteInput::defaults())));
        $app->post('/sites/new', new WebAction($web->save(...)));
        $app->post('/sites/branches', new WebAction((new SiteBranchesPage($web->sites,$web->github,$web->page))->branches(...)));
        $app->get('/sites/{id:[0-9]+}/edit', new WebAction(static function ($id) use ($web) {
            $site = $web->sites->find((int) $id);
            return $site ? (new SiteFormPage($web->tokens,$web->page))->form($site, (int) $id) : $web->notFound();
        }));
        $app->post('/sites/{id:[0-9]+}/edit', new WebAction(static fn ($id) => $web->save((int) $id)));
        $app->get('/sites/{id:[0-9]+}/delete', new WebAction(static function ($id) use ($web) {
            $site = $web->sites->find((int) $id);
            return $site ? $web->render('delete', ['title' => 'Удалить сайт', 'site' => SiteProjection::publicSite($site)]) : $web->notFound();
        }));
        $app->post('/sites/{id:[0-9]+}/delete', new WebAction(static function ($id) use ($web) {
            if (!$web->sites->find((int) $id)) {
                return $web->notFound();
            }
            $web->sites->delete((int) $id);
            $_SESSION['notice'] = 'Сайт удалён.';
            return $web->redirect('/');
        }));
        $app->post('/sites/{id:[0-9]+}/check', new WebAction(static fn ($id) => $web->manual->check((int) $id)));
    }
    private function save(?int $id = null): Response
    {
        $existing = $id === null ? null : $this->sites->find($id);
        if ($id !== null && !$existing) {
            return $this->notFound();
        }
        try {
            SiteInput::normalize($_POST); // Reject invalid endpoint input before any Git request.
            $this->github->validate(SiteProjection::withoutEndpointCredential($_POST), SiteProjection::gitCredentials($existing));
            $this->sites->save($_POST, $id);
            $_SESSION['notice'] = $id === null ? 'Сайт добавлен. Запустите первую проверку.' : 'Настройки сохранены. Запустите новую проверку.';
            return $this->redirect('/');
        } catch (ValidationException | \RuntimeException $e) {
            $data = RejectedSiteForm::data($existing,$id);
            $errors = SiteFormErrors::errors($e);
            SiteFormPrivacy::sanitize($data,$errors);
            return (new SiteFormPage($this->tokens,$this->page))->form($data, $id, $errors, 422);
        }
    }


}
