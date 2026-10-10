<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class GitHubMigrationTest
{
    #[Test]
    public function upgradesVersionTwoAtomicallyAndPreservesRowsOnFailure(): void
    {
        foreach (['CREATE TABLE github_cooldowns', 'PRAGMA main.user_version = 3', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
            $db->exec("ALTER TABLE sites ADD COLUMN health_error_code TEXT;
                ALTER TABLE sites ADD COLUMN health_http_status INTEGER;
                PRAGMA user_version = 2;
                INSERT INTO sites (name,url,repository,github_token,online,latest_release,health_error_code)
                VALUES ('Synthetic','https://example.com','example/project','encrypted-fixture',1,'v1','http')");
            $before = $db->query('SELECT * FROM sites')->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\RuntimeException $caught) { $error = $caught; }
                Assert::instanceOf($error, \RuntimeException::class);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 2);
                Assert::same($db->query("SELECT name FROM sqlite_schema WHERE name='github_cooldowns'")->fetchColumn(), false);
                Assert::same($db->query('SELECT * FROM sites')->fetchAll(), $before);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same(array_intersect_key($db->query('SELECT * FROM sites')->fetch(), $before[0]), $before[0]);
            Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), 'memory', 'no dependency on WAL issue 8');
        }
    }
}
