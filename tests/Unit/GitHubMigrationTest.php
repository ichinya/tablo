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
        foreach (['CREATE TABLE github_cooldowns', 'PRAGMA main.user_version = ' . Database::CURRENT_SCHEMA_VERSION, 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common2.sql')));
            $db->exec("
                INSERT INTO sites (name,url,repository,github_token,online,latest_release,health_error_code)
                VALUES ('Synthetic','https://example.com','example/project','encrypted-fixture',1,'v1','http')");
            $before = \Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $error = null;
                try { Database::migrate($db); } catch (\RuntimeException $caught) { $error = $caught; }
                Assert::instanceOf($error, \RuntimeException::class);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 2);
                Assert::same(\Tablo\SqlStatement::checked($db->query("SELECT name FROM sqlite_schema WHERE name='github_cooldowns'"))->fetchColumn(), false);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetchAll(), $before);
                $db->failBefore = null;
            }
            Database::migrate($db);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION);
            Assert::same(array_intersect_key(\Tablo\JsonRecord::checked(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch()), \Tablo\JsonRecord::checked(\Tablo\Tests\Support\FixtureValue::at($before, 0))), \Tablo\Tests\Support\FixtureValue::at($before, 0));
            Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA journal_mode'))->fetchColumn(), 'memory', 'no dependency on WAL issue 8');
        }
    }
}
