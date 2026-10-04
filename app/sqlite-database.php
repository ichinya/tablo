<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class Database
{
    public static function connect(?string $path = null): PDO
    {
        $configuredPath = getenv('TABLO_DB');
        $path ??= $configuredPath ? $configuredPath : dirname(__DIR__) . '/storage/tablo.sqlite';
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), permissions: 0o700, recursive: true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
        self::migrate($db);
        if ($path !== ':memory:') {
            // Best-effort POSIX permissions; Windows does not support the same file modes.
            // @mago-expect lint:no-error-control-operator
            @chmod($path, permissions: 0o600);
        }
        return $db;
    }

    public static function migrate(PDO $db): void
    {
        // Additive migrations preserve existing credentials and endpoint behaviour.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($db->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
            if (!in_array('github_token', $columns, strict: true)) {
                $db->exec('ALTER TABLE sites ADD COLUMN github_token TEXT');
            }
            if (!in_array('git_token_id', $columns, strict: true)) {
                $db->exec('ALTER TABLE sites ADD COLUMN git_token_id INTEGER REFERENCES git_tokens(id) ON DELETE RESTRICT');
            }
            $jsonColumns = [
                'version_json_path' => "TEXT NOT NULL DEFAULT ''",
                'health_check_mode' => "TEXT NOT NULL DEFAULT 'http' CHECK (health_check_mode IN ('http', 'json'))",
                'health_json_path' => "TEXT NOT NULL DEFAULT ''",
                'health_json_operator' => "TEXT NOT NULL DEFAULT '==' CHECK (health_json_operator IN ('>', '>=', '<', '<=', '!=', '==', 'contains'))",
                'health_json_expected_value' => "TEXT NOT NULL DEFAULT ''",
            ];
            foreach ($jsonColumns as $name => $definition) {
                if (in_array($name, $columns, strict: true)) { continue; }

                $db->exec("ALTER TABLE sites ADD COLUMN {$name} {$definition}");
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
