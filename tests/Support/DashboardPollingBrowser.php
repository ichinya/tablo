<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\SiteInput;
use Tablo\SiteRepository;
use Tablo\SqlStatement;

/** Saved-row producers for the real-browser polling fixture; never check upstreams. */
final class DashboardPollingBrowser
{
    /** @param list<int> $ids
     * @return array{addedId: int} */
    public static function mutate(DashboardModesFixture $fixture, array $ids): array
    {
        $db = $fixture->web->database();
        $update = SqlStatement::checked($db->prepare('UPDATE sites SET name=?, last_error=?, online=1, health_error_code=NULL, health_http_status=200, open_issues=0, open_prs=NULL, response_time_ms=0, checked_at=? WHERE id=?'));
        try { $update->execute(['Polling updated site', 'Polling saved error', gmdate('Y-m-d H:i:s'), FixtureValue::at($ids, 0)]); }
        finally { $update->closeCursor(); }
        $pause = SqlStatement::checked($db->prepare('UPDATE sites SET enabled=0 WHERE id=?'));
        try { $pause->execute([FixtureValue::at($ids, 1)]); }
        finally { $pause->closeCursor(); }
        $delete = SqlStatement::checked($db->prepare('DELETE FROM sites WHERE id=?'));
        try { $delete->execute([FixtureValue::at($ids, 2)]); }
        finally { $delete->closeCursor(); }
        return ['addedId' => self::add($fixture, 'Polling new site')];
    }

    /** @return array{addedIds: list<int>} */
    public static function large(DashboardModesFixture $fixture): array
    {
        $ids = [];
        for ($index = 0; $index < 125; ++$index) { $ids[] = self::add($fixture, 'Polling batch ' . $index); }
        return ['addedIds' => $ids];
    }

    private static function add(DashboardModesFixture $fixture, string $name): int
    {
        $sites = new SiteRepository($fixture->web->database(), new \Tablo\TokenVault($fixture->directory->path . '/key', external: true));
        return $sites->save(array_replace(SiteInput::defaults(), ['name' => $name, 'url' => 'https://polling.example.com',
            'repository' => 'example/polling', 'version_path' => '']));
    }
}
