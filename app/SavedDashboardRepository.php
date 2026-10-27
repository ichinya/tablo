<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

/** @phpstan-import-type Row from SavedDashboardRow */
final class SavedDashboardRepository
{
    public const MAXIMUM_RAW_BYTES = 8_388_608;

    public function __construct(private readonly PDO $db) {}

    /** Saved presentation inputs only; no credential join, provider, settlement or notice access.
     * @return list<Row> */
    public function all(): array
    {
        $statement = SqlStatement::checked($this->db->query("SELECT id, name, url, repository, branch,
            enabled, sort_order, online, checked_at, deployed_version, latest_release,
            deployed_commit, latest_commit, comparison_mode, last_error, health_error_code,
            health_http_status, open_issues, open_prs, response_time_ms,
            CASE WHEN version_path = '' THEN '' ELSE 'tracked' END AS version_path
            FROM sites ORDER BY sort_order, id"));
        $rows = [];
        $bytes = 0;
        try {
            while (true) {
                /** @var array<string, int|string|null>|false $row Selected SQL integer/text/null values or end of cursor. */
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) { break; }
                $bytes += self::bytes($row);
                if ($bytes > self::MAXIMUM_RAW_BYTES) { throw new DashboardStateTooLarge(); }
                $rows[] = SavedDashboardRow::checked($row);
            }
        } finally { $statement->closeCursor(); }
        return $rows;
    }

    /** @param array<string, int|string|null> $row */
    private static function bytes(array $row): int
    {
        $bytes = 0;
        foreach ($row as $value) { $bytes += is_string($value) ? strlen($value) : 16; }
        return $bytes;
    }
}
