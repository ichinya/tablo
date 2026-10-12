<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** @phpstan-import-type Owners from ScheduleSourceCopy */
final class LegacyDatabaseSchedule
{
    /** @return Owners */
    public static function anchors(string $owner): array
    {
        $source = file_get_contents($owner);
        if ($source === false) { throw new \RuntimeException('Cannot read historical scheduling owner'); }
        $additions = [
            ["        \$db->exec('BEGIN IMMEDIATE');\n        try {",
                "        \\DatabaseScheduleChild::beforeBegin();\n        \$db->exec('BEGIN IMMEDIATE');\n        \\DatabaseScheduleChild::afterBegin();\n        try {"],
        ];
        $candidate = str_contains($source, 'private static function ensureWal(PDO $db): void');
        switch (true) {
case ($candidate):

            $additions[] = ["            self::ensureWal(\$db);", "            \\DatabaseScheduleChild::beforeWal();\n            self::ensureWal(\$db);"];
            $additions[] = ["                // Cleanup failure never enters", "                \\DatabaseScheduleChild::attempt(\$error, \$cleanupFailed, isset(\$statement));\n                // Cleanup failure never enters"];
            $additions[] = ["        \$deadline = hrtime(true) + 5_000_000_000;", "        \$deadline = hrtime(true) + 5_000_000_000;\n        \\DatabaseScheduleChild::stage('phase-start', ['deadline' => \$deadline]);"];
            $additions[] = ["                        \$statement = \$db->prepare(\$sql);", "                        \\DatabaseScheduleChild::stage('attempt-start');\n                        \$statement = \$db->prepare(\$sql);"];

    break;
default:

            $start = "            \$statement = \$db->query('PRAGMA journal_mode = WAL');";
            $end = "            @chmod(\$path, 0600);";
            $additions[] = [$start, "            \\DatabaseScheduleChild::beforeWal();\n            try {\n" . $start];
            $additions[] = [$end, "            } catch (\\PDOException \$observed) {\n                \\DatabaseScheduleChild::attempt(\$observed, false, isset(\$statement));\n                throw \$observed;\n            }\n" . $end];

    break;
}
        return ['Database'=>[$owner,$additions]];
    }
}
