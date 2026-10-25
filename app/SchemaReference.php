<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class SchemaReference
{
    /** @param array{endpoint: bool} $features */
    public static function reference(int $version, array $features): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        BaseSchema::migrateToVersionOne($db);
        if ($version >= 2) { BaseSchema::migrateToVersionTwo($db); }
        if ($version >= 3) { BaseSchema::migrateToVersionThree($db); }
        if ($version >= 4) { BaseSchema::migrateToVersionFour($db); }
        if ($features['endpoint']) { $db->exec('ALTER TABLE sites ADD COLUMN endpoint_token TEXT'); }
        if ($version >= 5 && (!$features['endpoint'] || $version >= 8)) {
            HistorySchema::migrateToVersionFive($db, $version >= 8 ? HistoryCodeDomain::Redirect : HistoryCodeDomain::Legacy);
        }
        if ($version >= 6) { foreach (IncidentSchema::STATEMENTS as $statement) { $db->exec($statement); } }
        if ($version >= 7) { NotificationSchema::migrate($db); }
        if ($version >= 9) { SchemaLayoutUpgrade::addSessionSettings($db); }
        if ($version >= 10) { ReadApiTokenSchema::migrate($db); }
        return $db;
    }
}
