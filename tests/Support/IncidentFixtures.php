<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\SiteRepository;

final class IncidentFixtures
{
    public static function site(): array
    {
        return array_replace(SiteRepository::defaults(), ['name' => 'Service', 'url' => 'https://example.com', 'repository' => 'example/service']);
    }

    public static function accept(SiteRepository $sites, int $id, ?int $online, string $time, array $extra = []): bool
    {
        return $sites->storeCheck($sites->find($id), $extra + ['online' => $online, 'checked_at' => $time]);
    }

    public static function rows(PDO $db): array
    {
        $rows = [];
        foreach (['sites', 'check_history', 'incidents', 'incident_checkpoints', 'worker_progress', 'worker_runtime'] as $table) {
            $rows[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
        }
        return $rows;
    }

    public static function error(callable $action): ?\Throwable
    {
        try { $action(); } catch (\Throwable $error) { return $error; }
        return null;
    }
}
