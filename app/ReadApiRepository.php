<?php
declare(strict_types=1);

namespace Tablo;

require_once __DIR__ . '/read-api-repository.php';

final class ReadApiRepository
{
    public function __construct(private readonly \PDO $db) {}
    /** @return list<array> */
    public function page(string $afterId = '0', int $limit = 50): array { return read_api_rows($this->db, $afterId, $limit); }
    public function find(string $id): ?array { return read_api_row($this->db, $id); }

    public static function projectionFor(\PDO $db): string
    {
        if (!DatabaseSession::server($db)) { return self::projection(); }
        $columns = ['CAST(id AS CHAR) AS id', 'CAST(config_revision AS CHAR) AS config_revision',
            "OCTET_LENGTH(health_path) > 0 AS health_target_configured", "OCTET_LENGTH(version_path) > 0 AS tracks_version"];
        foreach (['enabled', 'sort_order', 'online', 'health_http_status', 'response_time_ms', 'open_issues', 'open_prs'] as $name) { $columns[] = $name; }
        foreach (['name' => 320, 'url' => 2048, 'provider' => 16, 'repository' => 140, 'branch' => 128,
            'comparison_mode' => 16, 'health_check_mode' => 16, 'created_at' => 20, 'updated_at' => 20, 'checked_at' => 20,
            'health_error_code' => 64, 'deployed_version' => 256, 'latest_release' => 256, 'deployed_commit' => 256, 'latest_commit' => 256] as $name => $maximum) {
            $columns[] = 'SUBSTRING(CAST(' . $name . ' AS BINARY), 1, ' . ($maximum + 1) . ') AS ' . $name;
        }
        return implode(', ', $columns);
    }

    /** BLOB slicing bounds bytes, including hostile oversized or malformed UTF-8 legacy TEXT. */
    public static function projection(): string
    {
        $columns = ["CASE WHEN typeof(id)='integer' THEN CAST(id AS TEXT) ELSE 'invalid' END AS id",
            "CASE WHEN typeof(config_revision)='integer' THEN CAST(config_revision AS TEXT) ELSE 'invalid' END AS config_revision",
            "CASE WHEN typeof(health_path)='text' THEN health_path <> '' ELSE NULL END AS health_target_configured",
            "CASE WHEN typeof(version_path)='text' THEN version_path <> '' ELSE NULL END AS tracks_version"];
        foreach (['enabled', 'sort_order', 'online', 'health_http_status', 'response_time_ms', 'open_issues', 'open_prs'] as $name) {
            $columns[] = "CASE WHEN {$name} IS NULL OR typeof({$name})='integer' THEN {$name} ELSE 'invalid' END AS {$name}";
        }
        foreach (['name' => 320, 'url' => 2048, 'provider' => 16, 'repository' => 140, 'branch' => 128,
            'comparison_mode' => 16, 'health_check_mode' => 16,
            'created_at' => 20, 'updated_at' => 20, 'checked_at' => 20, 'health_error_code' => 64,
            'deployed_version' => 256, 'latest_release' => 256, 'deployed_commit' => 256, 'latest_commit' => 256] as $name => $maximum) {
            $columns[] = "CASE WHEN {$name} IS NULL THEN NULL WHEN typeof({$name})='text' THEN substr(CAST({$name} AS BLOB),1," . ($maximum + 1) . ") ELSE 0 END AS {$name}";
        }
        return implode(', ', $columns);
    }
}
