<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

/** @phpstan-import-type Owners from ScheduleSourceCopy */
final class DatabaseScheduleOwners
{
    /** @return Owners */
    public static function anchors(string $root,string $owner): array
    {
        // Retain the explicit historical single-owner control when one is supplied.
        if ($owner !== $root.'/app/Database.php') { return LegacyDatabaseSchedule::anchors($owner); }
        return [
            'Database'=>[$owner,[["            self::ensureWal(\$db);","            \\DatabaseScheduleChild::beforeWal();\n            self::ensureWal(\$db);"]]],
            'SchemaUpgrade'=>[$root.'/app/SchemaUpgrade.php',[["        \$db->exec('BEGIN IMMEDIATE');\n        try {", "        \\DatabaseScheduleChild::beforeBegin();\n        \$db->exec('BEGIN IMMEDIATE');\n        \\DatabaseScheduleChild::afterBegin();\n        try {"]]],
            'WalNegotiation'=>[$root.'/app/WalNegotiation.php',[["        \$deadline = MonotonicTime::integer(hrtime(true)) + 5_000_000_000;", "        \$deadline = MonotonicTime::integer(hrtime(true)) + 5_000_000_000;\n        \\DatabaseScheduleChild::stage('phase-start', ['deadline' => \$deadline]);"]]],
            'WalAttempt'=>[$root.'/app/WalAttempt.php',[["                        \$statement = \$db->prepare(\$sql);", "                        \\DatabaseScheduleChild::stage('attempt-start');\n                        \$statement = \$db->prepare(\$sql);"],
                ["                    unset(\$statement);", "                    unset(\$statement);\n                    \\DatabaseScheduleChild::attempt(\$error,\$cleanupFailed,isset(\$statement));"]]],
        ];
    }
}
