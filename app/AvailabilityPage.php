<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

final class AvailabilityPage extends WebPage
{
    public function __construct(private readonly AvailabilityRepository $availability, PageResponse $page) { parent::__construct($page); }

    public function register(Micro $app): void
    { $app->get('/sites/{id:[0-9]+}/history', new WebAction($this->history(...), SessionActivity::HumanPage)); }

    private function history(mixed $id): Response
    {
        try { $siteId = IncidentQuery::integer($id, PHP_INT_MAX); }
        catch (\InvalidArgumentException) { return $this->notFound(); }
        try {
            $period = AvailabilityPeriod::select(array_key_exists('period', $_GET) ? $_GET['period'] : '24h');
            $read = $this->availability->read($siteId, $period['from'], $period['to']);
            return $this->render('availability', AvailabilityPresenter::view($read, $period));
        } catch (\OutOfBoundsException) { return $this->notFound(); }
        catch (\InvalidArgumentException) {
            return $this->render('error', ['title' => 'Некорректный запрос', 'message' => 'Выберите период: 24 часа, 7 дней или 30 дней.'], 422);
        }
    }
}
