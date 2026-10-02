<?php
declare(strict_types=1);

namespace Tablo;

use PDO;

final class Database
{
    public static function connect(?string $path = null): PDO
    {
        $path ??= getenv('TABLO_DB') ?: dirname(__DIR__) . '/storage/tablo.sqlite';
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
        self::migrate($db);
        if ($path !== ':memory:') {
            @chmod($path, 0600);
        }
        return $db;
    }

    public static function migrate(PDO $db): void
    {
        // Additive migration for installations created before per-site tokens.
        $db->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($db->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
            if (!in_array('github_token', $columns, true)) {
                $db->exec('ALTER TABLE sites ADD COLUMN github_token TEXT');
            }
            if (!in_array('git_token_id', $columns, true)) {
                $db->exec('ALTER TABLE sites ADD COLUMN git_token_id INTEGER REFERENCES git_tokens(id) ON DELETE RESTRICT');
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
