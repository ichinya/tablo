<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class IncidentPage extends WebPage
{
    public function __construct(private readonly IncidentRepository $incidents, PageResponse $page) { parent::__construct($page); }
    public function register(Micro $app): void
    {
        $web = $this;
        $app->get('/incidents', new WebAction($web->incidentList(...), SessionActivity::HumanPage));
    }
    private function incidentList(): Response
    {
        try {
            $siteId = array_key_exists('site_id', $_GET) ? IncidentQuery::integer($_GET['site_id'], PHP_INT_MAX) : null;
            $limit = array_key_exists('limit', $_GET) ? IncidentQuery::integer($_GET['limit'], 100) : 50;
            $cursor = $_GET['cursor'] ?? null;
            if ($cursor !== null && !is_string($cursor)) { throw new \InvalidArgumentException('Invalid incident query.'); }
            $page = $this->incidents->page($siteId, $limit, $cursor);
            $next = $page['next_cursor'] === null ? null : '/incidents?' . http_build_query(
                array_filter(['site_id' => $siteId, 'limit' => $limit, 'cursor' => $page['next_cursor']], static fn ($value) => $value !== null));
            return $this->render('incidents', ['title' => 'Инциденты', 'incidents' => array_map([IncidentPresenter::class, 'row'], $page['rows']),
                'site_filter' => $siteId ?? '', 'next_page' => $next]);
        } catch (\OutOfBoundsException) { return $this->notFound(); }
        catch (\InvalidArgumentException) {
            return $this->render('error', ['title' => 'Некорректный запрос', 'message' => 'Проверьте фильтр и ссылку на страницу инцидентов.'], 422);
        }
    }
}
