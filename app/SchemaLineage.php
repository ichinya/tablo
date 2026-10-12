<?php
declare(strict_types=1);

namespace Tablo;

final class SchemaLineage
{
    public static function classify(\PDO $db): array
    {
        $version = SchemaVersion::schemaVersion($db);
        $retries = 0;
        while (true) {
            try { return SchemaLayout::observe($db, $version); }
            catch (\RuntimeException $failure) {
                if ($failure instanceof \PDOException || $retries >= 1) { throw $failure; }
                // Separate autocommit metadata reads may straddle a real migration.
                // Retry only a proven forward transition; corrupt unchanged layouts still refuse.
                try { $observed = SchemaVersion::schemaVersion($db); }
                catch (\Throwable $discardedInspection) { unset($discardedInspection); throw $failure; }
                if ($observed <= $version) { throw $failure; }
                $version = $observed;
                ++$retries;
            }
        }
    }

}
