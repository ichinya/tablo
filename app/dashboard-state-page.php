<?php
declare(strict_types=1);

namespace Tablo;

use Phalcon\Http\Response;

/** @phpstan-import-type View from Presenter */
// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class DashboardStatePage
{
    public const MAXIMUM_JSON_BYTES = 2_097_152;

    public function __construct(private readonly SavedDashboardRepository $sites, private readonly PageResponse $page) {}

    public function state(): Response
    {
        $snapshot = WebSession::release();
        try {
            $sites = array_map(Presenter::site(...), $this->sites->all());
            $stats = self::statistics($sites);
            $fragment = $this->page->render('dashboard-snapshot', ['sites' => $sites, 'stats' => $stats, 'csrf' => $snapshot->csrf]);
            $json = JsonEncoding::encode(['dashboard_version' => 1, 'csrf' => $snapshot->csrf, 'stats' => $stats,
                'html' => $fragment->getContent()], JSON_UNESCAPED_UNICODE);
            if (strlen($json) > self::MAXIMUM_JSON_BYTES) { throw new DashboardStateTooLarge(); }
            $response = new Response();
            $response->setStatusCode(200);
            $response->setContentType('application/json', 'utf-8');
            $response->setContent($json);
        } catch (DashboardStateTooLarge) {
            $response = $this->page->json(['code' => 'too_large', 'error' => 'Dashboard state is too large.'], 413);
        }
        // Reopening rereads current identity and lifetime; an old prepared fragment grants no authority.
        $refusal = WebSession::resume($snapshot);
        $response = $refusal ?? $response;
        $response->setHeader('Cache-Control', 'no-store, private');
        return $response;
    }

    /** @param list<View> $sites
     * @return array{total: int, online: int, attention: int, paused: int} */
    private static function statistics(array $sites): array
    {
        $stats = ['total' => count($sites), 'online' => 0, 'attention' => 0, 'paused' => 0];
        foreach ($sites as $site) {
            if (!$site['enabled']) { ++$stats['paused']; }
            if ($site['enabled'] && $site['online'] === 1) { ++$stats['online']; }
            if ($site['attention']) { ++$stats['attention']; }
        }
        return $stats;
    }
}
