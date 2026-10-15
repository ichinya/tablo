<?php
declare(strict_types=1);

namespace Tablo;

final class SiteBranchesPage extends WebPage
{
    public function __construct(private readonly SiteRepository $sites,private readonly GitHubConnection $github,PageResponse $page) { parent::__construct($page); }
    public function branches(): \Phalcon\Http\Response
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
            return $this->json($this->github->branches(SiteProjection::withoutEndpointCredential($_POST), SiteProjection::withoutEndpointCredential($site)));
        } catch (ValidationException $e) {
            return $this->json(['error' => implode(' ', $e->errors)], 422);
        }
    }
}
