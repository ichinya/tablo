<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use PDO;
use Tablo\AvailabilityData;
use Tablo\SchemaShape;
use Tablo\SqlStatement;

/**
 * @phpstan-import-type Site from AvailabilityData
 * @phpstan-import-type Sample from AvailabilityData
 */
final class AvailabilityFixtures
{
    /** @return Site */
    public static function site(): array
    { return ['id' => 1, 'name' => 'Availability fixture', 'enabled' => 1, 'current_revision' => 0]; }

    /** @return Sample */
    public static function sample(int $id, ?int $online, ?int $milliseconds = null,
        string $time = '2026-03-01T00:00:00Z', int $revision = 0): array
    {
        return ['id' => $id, 'config_revision' => $revision, 'checked_at' => $time,
            'online' => $online, 'response_time_ms' => $milliseconds];
    }

    public static function bulk(PDO $db, int $siteId, int $count, string $time): void
    {
        $statement = SqlStatement::checked($db->prepare("INSERT INTO check_history(site_id,config_revision,checked_at,online,response_time_ms,version_status)
            VALUES(?,0,?,1,0,'skipped')"));
        $db->beginTransaction();
        try {
            for ($index = 0; $index < $count; $index++) { $statement->execute([$siteId, $time]); }
            $db->commit();
        } finally {
            $statement->closeCursor();
            if ($db->inTransaction()) { $db->rollBack(); }
        }
    }

    /** @return array<string,list<array>> */
    public static function protectedRows(PDO $db): array
    {
        $rows = [];
        foreach (['sites', 'check_history', 'git_tokens', 'github_cooldowns', 'installation_settings', 'incidents',
            'incident_checkpoints', 'notification_settings', 'notification_checkpoints', 'notification_slots', 'worker_runtime', 'worker_progress'] as $table) {
            $rows[$table] = SchemaShape::rows($db, 'SELECT * FROM ' . $table . ' ORDER BY 1');
        }
        return $rows;
    }
}
