<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class HistorySchema
{
    public static function migrateToVersionFive(PDO $db, HistoryCodeDomain $domain = HistoryCodeDomain::Legacy): void
    {
        $sql = "CREATE TABLE check_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            config_revision INTEGER NOT NULL CHECK (typeof(config_revision) = 'integer' AND config_revision >= 0),
            checked_at TEXT NOT NULL CHECK (typeof(checked_at) = 'text' AND length(CAST(checked_at AS BLOB)) = 20
                AND checked_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'
                AND substr(checked_at, 1, 4) <> '0000'
                AND strftime('%Y-%m-%dT%H:%M:%SZ', checked_at, '+0 seconds') IS checked_at),
            online INTEGER CHECK (online IS NULL OR (typeof(online) = 'integer' AND online IN (0, 1))),
            health_error_code TEXT CHECK (health_error_code IN ('http', 'timeout', 'refused', 'dns', 'ssrf', 'tls', 'size',
                'invalid-response', 'invalid-url', 'network', 'check-error', 'json-condition')),
            health_http_status INTEGER CHECK (health_http_status IS NULL OR (typeof(health_http_status) = 'integer' AND health_http_status BETWEEN 100 AND 599)),
            response_time_ms INTEGER CHECK (response_time_ms IS NULL OR (typeof(response_time_ms) = 'integer' AND response_time_ms BETWEEN 0 AND 2147483647)),
            deployed_version TEXT CHECK (deployed_version IS NULL OR (typeof(deployed_version) = 'text' AND length(CAST(deployed_version AS BLOB)) BETWEEN 1 AND 200)),
            deployed_commit TEXT CHECK (deployed_commit IS NULL OR (typeof(deployed_commit) = 'text' AND length(deployed_commit) BETWEEN 7 AND 64 AND deployed_commit NOT GLOB '*[^a-f0-9]*')),
            latest_release TEXT CHECK (latest_release IS NULL OR (typeof(latest_release) = 'text' AND length(CAST(latest_release AS BLOB)) BETWEEN 1 AND 200)),
            latest_commit TEXT CHECK (latest_commit IS NULL OR (typeof(latest_commit) = 'text' AND length(latest_commit) BETWEEN 7 AND 64 AND latest_commit NOT GLOB '*[^a-f0-9]*')),
            version_status TEXT NOT NULL CHECK (version_status IN ('skipped', 'ok', 'error')),
            version_error_code TEXT CHECK (version_error_code IN ('http', 'invalid-data', 'timeout', 'refused', 'dns', 'ssrf', 'tls', 'size',
                'invalid-response', 'invalid-url', 'network', 'check-error', 'json-condition')),
            version_http_status INTEGER CHECK (version_http_status IS NULL OR (typeof(version_http_status) = 'integer' AND version_http_status BETWEEN 100 AND 599)),
            release_error_code TEXT CHECK (release_error_code IN ('access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
                'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error')),
            release_http_status INTEGER CHECK (release_http_status IS NULL OR (typeof(release_http_status) = 'integer' AND release_http_status BETWEEN 100 AND 599)),
            commit_error_code TEXT CHECK (commit_error_code IN ('access', 'rate-limit', 'budget', 'credential-changed', 'renamed',
                'branch-unconfirmed', 'release-unconfirmed', 'incomplete-search', 'invalid-data', 'unavailable', 'check-error')),
            commit_http_status INTEGER CHECK (commit_http_status IS NULL OR (typeof(commit_http_status) = 'integer' AND commit_http_status BETWEEN 100 AND 599)),
            CHECK ((version_status = 'error') = (version_error_code IS NOT NULL))
        )";
        if ($domain === HistoryCodeDomain::Redirect) {
            $sql = str_replace("'json-condition'", replace: "'json-condition', 'redirect-location-missing', 'redirect-location-invalid', 'redirect-origin', 'redirect-cycle', 'redirect-limit'", subject: $sql);
        }
        $db->exec($sql);
        $db->exec('CREATE INDEX check_history_site_time ON check_history(site_id, checked_at, id)');
        $db->exec('CREATE INDEX check_history_time ON check_history(checked_at, id)');
    }
    public static function validateHistoryDependencies(PDO $db, array $objects): void
    {
        foreach ($objects as $name => $object) {
            if ($object['tbl_name'] === 'check_history' && !in_array($name,
                ['check_history', 'check_history_site_time', 'check_history_time'], strict: true)) { SchemaShape::refuse(); }
            if ($object['type'] !== 'table') { continue; }
            // Names are quoted from actual SQLite metadata, never interpolated as SQL syntax.
            $quoted = '"' . str_replace('"', replace: '""', subject: $object['name']) . '"';
            foreach (SchemaShape::rows($db, 'PRAGMA main.foreign_key_list(' . $quoted . ')') as $foreign) {
                if (!(strtolower($foreign['table']) === 'check_history')) { continue; }
 SchemaShape::refuse();
            }
        }
    }
    public static function widenHistory(PDO $db): void
    {
        HistorySchema::validateHistoryDependencies($db, SchemaShape::objects($db));
        $sequence = SchemaShape::rows($db, "SELECT seq FROM main.sqlite_sequence WHERE name = 'check_history'");
        $oldSequence = $sequence[0]['seq'] ?? 0;
        $expected = SchemaReference::reference(Database::CURRENT_SCHEMA_VERSION, ['endpoint' => true]);
        $sql = SchemaShape::objects($expected)['check_history']['sql'];
        $db->exec(str_replace('CREATE TABLE check_history', replace: 'CREATE TABLE _tablo_history_upgrade', subject: $sql));
        $columns = implode(', ', array_map(static fn (array $column): string => '"' . $column['name'] . '"', BaseSchema::columns($db, 'check_history')));
        $db->exec('INSERT INTO _tablo_history_upgrade (' . $columns . ') SELECT ' . $columns . ' FROM check_history');
        $missing = SchemaShape::rows($db, 'SELECT ' . $columns . ' FROM check_history EXCEPT SELECT ' . $columns . ' FROM _tablo_history_upgrade');
        $extra = SchemaShape::rows($db, 'SELECT ' . $columns . ' FROM _tablo_history_upgrade EXCEPT SELECT ' . $columns . ' FROM check_history');
        if ($missing !== [] || $extra !== []) { SchemaShape::refuse(); }
        $db->exec('DROP TABLE check_history');
        $db->exec('ALTER TABLE _tablo_history_upgrade RENAME TO check_history');
        $db->exec('CREATE INDEX check_history_site_time ON check_history(site_id, checked_at, id)');
        $db->exec('CREATE INDEX check_history_time ON check_history(checked_at, id)');
        $statement = $db->prepare("UPDATE main.sqlite_sequence SET seq = MAX(seq, ?) WHERE name = 'check_history'");
        try { $statement->bindValue(1, $oldSequence, PDO::PARAM_INT); $statement->execute(); }
        finally { $statement->closeCursor(); }
        if ($sequence !== [] && SchemaShape::rows($db, "SELECT seq FROM main.sqlite_sequence WHERE name = 'check_history'") === []) {
            $statement = $db->prepare("INSERT INTO main.sqlite_sequence (name, seq) VALUES ('check_history', ?)");
            try { $statement->bindValue(1, $oldSequence, PDO::PARAM_INT); $statement->execute(); }
            finally { $statement->closeCursor(); }
        }
    }

}
