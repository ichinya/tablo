<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\SiteRepository;
use Tablo\SiteInput;

/** @phpstan-import-type SiteData from SiteInput */
final class IncidentFixtures
{
    /** @return SiteData */
    public static function site(): array
    {
        return [...SiteInput::defaults(), 'name' => 'Service', 'url' => 'https://example.com', 'repository' => 'example/service'];
    }

    public static function accept(SiteRepository $sites, int $id, ?int $online, string $time, array $extra = []): bool
    {
        return $sites->storeCheck(\Tablo\Tests\Support\FixtureValue::row($sites->find($id)), $extra + ['online' => $online, 'checked_at' => $time]);
    }

    /** @return array<string, list<array>> */
    public static function rows(PDO $db): array
    {
        $rows = [];
        foreach (['sites', 'check_history', 'incidents', 'incident_checkpoints', 'worker_progress', 'worker_runtime'] as $table) {
            $rows[$table] = \Tablo\SqlRows::all(\Tablo\SqlStatement::checked($db->query('SELECT * FROM ' . $table . ' ORDER BY 1'))->fetchAll(), \Tablo\JsonRecord::checked(...));
        }
        return $rows;
    }

    public static function error(callable $action): ?\Throwable
    {
        try { $action(); } catch (\Throwable $error) { return $error; }
        return null;
    }
}
