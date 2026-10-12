<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\Database;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;



final class DatabaseTest
{







    #[Test]
    public function initializesFileAndMemoryWithVersionAndConnectionSettings(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-');
        try {
            foreach ([':memory:', $directory->path . '/nested/test.sqlite'] as $path) {
                $db = Database::connect($path);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
                Assert::same((int) $db->query('PRAGMA foreign_keys')->fetchColumn(), 1);
                Assert::same((int) $db->query('PRAGMA busy_timeout')->fetchColumn(), 5000);
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
                Assert::same($db->query('SELECT name FROM sqlite_schema WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name')
                    ->fetchAll(PDO::FETCH_COLUMN), ['check_history', 'git_tokens', 'github_cooldowns', 'incident_checkpoints', 'incidents', 'installation_settings', 'login_limits', 'notification_checkpoints', 'notification_settings', 'notification_slots', 'sites', 'users', 'worker_progress', 'worker_runtime']);
                Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), $path === ':memory:' ? 'memory' : 'wal');
                unset($db);
            }
            $db = Database::connect($directory->path . '/nested/test.sqlite');
            Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'wal');
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
        } finally {
            unset($db);
            $directory->close();
        }
    }



    #[Test]
    public function rejectsUnsupportedWalWithoutFallbackOrPrivatePathInPolicyError(): void
    {
        // SQLite's unnamed temporary database cannot enter WAL and returns delete.
        Assert::same(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::connect('')),
            'SQLite WAL is required for file databases; use writable local storage.');
        // An explicit memory URI is also unsupported; only :memory: is the memory policy.
        Assert::same(\Tablo\Tests\Support\DatabaseAssertions::errorMessage(static fn () => Database::connect('file::memory:?cache=shared')),
            'SQLite WAL is required for file databases; use writable local storage.');
    }
}
