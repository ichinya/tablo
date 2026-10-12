<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class SchemaLayoutUpgrade
{
    public static function apply(\PDO $db,array $layout): void
    {
            $version = $layout['version'];
            while ($version < 4) {
                match ($version) {
                    0 => BaseSchema::migrateToVersionOne($db),
                    1 => BaseSchema::migrateToVersionTwo($db),
                    2 => BaseSchema::migrateToVersionThree($db),
                    3 => BaseSchema::migrateToVersionFour($db),
                };
                ++$version;
            }
            if (!$layout['endpoint']) { $db->exec('ALTER TABLE sites ADD COLUMN endpoint_token TEXT'); }
            if (!$layout['history']) { HistorySchema::migrateToVersionFive($db, HistoryCodeDomain::Redirect); }
            if ($layout['history'] && $layout['version'] !== Database::CURRENT_SCHEMA_VERSION) { HistorySchema::widenHistory($db); }
            if (!$layout['incidents']) { foreach (IncidentSchema::STATEMENTS as $statement) { $db->exec($statement); } }
            if (!$layout['notifications']) { NotificationSchema::migrate($db); }
    }
}
