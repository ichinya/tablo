<?php
declare(strict_types=1);

namespace Tablo;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
/** @phpstan-import-type Layout from SchemaLayout */
final class SchemaLayoutUpgrade
{
    /** @param Layout $layout */
    public static function apply(\PDO $db,array $layout): void
    {
            $version = $layout['version'];
            while ($version < 4) {
                match ($version) {
                    0 => BaseSchema::migrateToVersionOne($db),
                    1 => BaseSchema::migrateToVersionTwo($db),
                    2 => BaseSchema::migrateToVersionThree($db),
                    3 => BaseSchema::migrateToVersionFour($db),
                    default => SchemaShape::refuse(),
                };
                ++$version;
            }
            if (!$layout['endpoint']) { $db->exec('ALTER TABLE sites ADD COLUMN endpoint_token TEXT'); }
            if (!$layout['history']) { HistorySchema::migrateToVersionFive($db, HistoryCodeDomain::Redirect); }
            if ($layout['history'] && $layout['version'] < 8) { HistorySchema::widenHistory($db); }
            if (!$layout['incidents']) { foreach (IncidentSchema::STATEMENTS as $statement) { $db->exec($statement); } }
            if (!$layout['notifications']) { NotificationSchema::migrate($db); }
            if ($layout['version'] < 9) { self::addSessionSettings($db); }
    }

    public static function addSessionSettings(\PDO $db): void
    {
        $db->exec("ALTER TABLE installation_settings ADD COLUMN session_idle_timeout_minutes INTEGER NOT NULL DEFAULT 30
            CHECK (typeof(session_idle_timeout_minutes) = 'integer' AND session_idle_timeout_minutes BETWEEN 1 AND 720)");
    }
}
