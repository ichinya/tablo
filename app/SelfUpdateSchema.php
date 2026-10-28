<?php
declare(strict_types=1);

namespace Tablo;

final class SelfUpdateSchema
{
    public static function sql(): string
    {
        return "CREATE TABLE self_update (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
            interval_minutes INTEGER NOT NULL DEFAULT 360 CHECK (interval_minutes BETWEEN 1 AND 525600),
            revision INTEGER NOT NULL DEFAULT 0 CHECK (revision >= 0),
            build_fingerprint TEXT NOT NULL DEFAULT '',
            observed_sha TEXT,
            status TEXT NOT NULL DEFAULT 'no_data' CHECK (status IN ('no_data', 'current', 'behind', 'ahead', 'diverged', 'force_pushed', 'unknown')),
            latest_sha TEXT,
            new_commits INTEGER CHECK (new_commits IS NULL OR new_commits >= 0),
            successful_at INTEGER,
            attempted_at INTEGER,
            error_code TEXT,
            failures INTEGER NOT NULL DEFAULT 0 CHECK (failures >= 0),
            next_due INTEGER NOT NULL DEFAULT 0 CHECK (next_due >= 0),
            claim_token TEXT,
            claim_until INTEGER NOT NULL DEFAULT 0 CHECK (claim_until >= 0)
        )";
    }

    public static function migrate(\PDO $db): void
    { $db->exec(self::sql()); $db->exec('INSERT INTO self_update (id) VALUES (1)'); }
}
