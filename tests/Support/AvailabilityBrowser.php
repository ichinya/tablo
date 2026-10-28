<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use RuntimeException;
use Tablo\JsonRecord;
use Tablo\SiteInput;
use Tablo\SiteRepository;
use Tablo\SqlRows;
use Tablo\SqlStatement;
use Tablo\TokenVault;

/**
 * Synthetic accepted observations in the browser launcher's owned SQLite database.
 * @phpstan-import-type SiteData from SiteInput
 */
final class AvailabilityBrowser
{
    public const NAME = 'История <script>window.availabilityUnsafe=1</script>';

    /** @return array{availabilityIds:array{mixed:int,paused:int,empty:int,single:int,unknown:int,overflow:int},availabilityName:string} */
    public static function seed(DashboardModesFixture $fixture): array
    {
        $db = $fixture->web->database();
        $sites = new SiteRepository($db, new TokenVault($fixture->directory->path . '/key', external: true));
        $anchor = time() - 3600;
        $mixed = self::add($sites, self::NAME);
        self::accept($sites, $mixed, 1, 0, $anchor - 7200);
        self::accept($sites, $mixed, 1, 10, $anchor - 5400);
        self::accept($sites, $mixed, 0, 30, $anchor - 5400);
        self::accept($sites, $mixed, null, 999, $anchor - 5400);
        $sites->save([...self::input(self::NAME), 'branch' => 'history-second-revision'], $mixed);
        self::accept($sites, $mixed, 1, null, $anchor - 3600);
        self::accept($sites, $mixed, null, null, $anchor - 1800);
        self::accept($sites, $mixed, 1, 80, $anchor - 172_800);
        self::accept($sites, $mixed, 0, 160, $anchor - 864_000);
        self::accept($sites, $mixed, null, null, $anchor - 2_678_400);
        $paused = self::add($sites, 'История на паузе');
        self::accept($sites, $paused, 1, 0, $anchor);
        self::accept($sites, $paused, 0, 40, $anchor - 1200);
        $sites->save([...self::input('История на паузе'), 'enabled' => 0], $paused);
        $empty = self::add($sites, 'История без проверок');
        $single = self::add($sites, 'История одной проверки');
        self::accept($sites, $single, 1, 0, $anchor);
        $unknown = self::add($sites, 'История Unknown');
        self::accept($sites, $unknown, null, 999, $anchor);
        self::accept($sites, $unknown, null, null, $anchor - 1200);
        $overflow = self::add($sites, 'История сверх лимита');
        self::accept($sites, $overflow, 1, 10, $anchor);
        self::overflow($db, $overflow);
        return ['availabilityIds' => ['mixed' => $mixed, 'paused' => $paused, 'empty' => $empty,
            'single' => $single, 'unknown' => $unknown, 'overflow' => $overflow], 'availabilityName' => self::NAME];
    }

    /** The browser receives only a digest, never persisted payloads. */
    public static function snapshot(PDO $db): string
    {
        $rows = [];
        foreach (['sites', 'check_history', 'incidents', 'incident_checkpoints', 'notification_settings',
            'notification_checkpoints', 'notification_slots', 'worker_progress', 'worker_runtime'] as $table) {
            $statement = SqlStatement::checked($db->query('SELECT * FROM ' . $table . ' ORDER BY 1'));
            try { $rows[$table] = SqlRows::all($statement->fetchAll(), JsonRecord::checked(...)); }
            finally { $statement->closeCursor(); }
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** @return SiteData */
    private static function input(string $name): array
    {
        return [...SiteInput::defaults(), 'name' => $name,
            'url' => 'https://availability.example.com/private/availability_url_secret',
            'health_path' => '/availability_health_secret', 'repository' => 'example/availability'];
    }

    private static function add(SiteRepository $sites, string $name): int
    { return $sites->save(self::input($name)); }

    private static function accept(SiteRepository $sites, int $id, ?int $online, ?int $milliseconds, int $epoch): void
    {
        $accepted = $sites->storeCheck(FixtureValue::row($sites->find($id)), ['online' => $online,
            'response_time_ms' => $milliseconds, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $epoch),
            'last_error' => 'availability_private_diagnostic', 'deployed_version' => 'availability_private_version']);
        if (!$accepted) { throw new RuntimeException('Synthetic browser history was not accepted.'); }
    }

    private static function overflow(PDO $db, int $id): void
    {
        // Copy the measurement projection of a real accepted row in this fixture only.
        // The bounded producer avoids 12,000 individual settlement transactions.
        $statement = SqlStatement::checked($db->prepare('INSERT INTO check_history
            (site_id, config_revision, checked_at, online, response_time_ms, version_status)
            SELECT site_id, config_revision, checked_at, online, response_time_ms, version_status
            FROM check_history WHERE site_id = ? ORDER BY id LIMIT 1'));
        $db->beginTransaction();
        try {
            for ($index = 0; $index < 12_000; $index++) { $statement->execute([$id]); $statement->closeCursor(); }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $error;
        } finally { $statement->closeCursor(); }
    }
}
