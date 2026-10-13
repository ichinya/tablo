<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** @phpstan-type Layout array{endpoint: bool, history: bool, incidents: bool, notifications: bool, version: int} */
final class SchemaLayout
{
    private const LINEAGES = [
        0 => [[false, false, false, false]],
        1 => [[false, false, false, false]],
        2 => [[false, false, false, false]],
        3 => [[false, false, false, false]],
        4 => [[false, false, false, false]],
        5 => [[true, false, false, false], [false, true, false, false]],
        6 => [[false, true, true, false]],
        7 => [[false, true, true, true]],
        8 => [[true, true, true, true]],
        9 => [[true, true, true, true]],
    ];
    /** @return Layout */
    public static function observe(PDO $db, int $version): array
    {
        $objects = SchemaShape::objects($db);
        $endpoint = in_array('endpoint_token', array_column(BaseSchema::columns($db, 'sites'), 'name'), strict: true);
        $groups = [
            'history' => ['check_history', 'check_history_site_time', 'check_history_time'],
            'incidents' => ['incidents', 'incidents_unresolved', 'incidents_site_id', 'incident_checkpoints'],
            'notifications' => ['notification_settings', 'notification_checkpoints', 'notification_slots', 'notification_due', 'notification_eligible'],
        ];
        $present = [];
        foreach ($groups as $group => $names) {
            $count = count(array_intersect($names, array_keys($objects)));
            if ($count !== 0 && $count !== count($names)) { SchemaShape::refuse(); }
            $present[$group] = $count !== 0;
        }
        if (($objects['_tablo_history_upgrade'] ?? null) !== null) { SchemaShape::refuse(); }
        $features = [$endpoint, $present['history'], $present['incidents'], $present['notifications']];
        if (!array_key_exists($version, self::LINEAGES)) { SchemaShape::refuse(); }
        $valid = in_array($features, self::LINEAGES[$version], strict: true);
        if (!$valid) { SchemaShape::refuse(); }
        SchemaShape::assert($db, SchemaReference::reference($version, ['endpoint' => $endpoint]), $version);
        if ($present['history'] && $version < 8) { HistorySchema::validateHistoryDependencies($db, $objects); }
        if (SchemaShape::rows($db, 'PRAGMA main.foreign_key_check') !== []) { SchemaShape::refuse(); }
        return ['version' => $version, 'endpoint' => $endpoint, ...$present];
    }
}
