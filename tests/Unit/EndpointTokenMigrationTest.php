<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenMigrationTest
{
    #[Test]
    public function upgradeIsNullableAtomicAndCurrentOrFutureSchemasDoNoDdl(): void
    {
        foreach (['ALTER TABLE sites ADD COLUMN endpoint_token', 'PRAGMA main.user_version = 9', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common4.sql')));
            $db->exec("
                INSERT INTO users(id,password_hash) VALUES(1,'synthetic-preserved');
                INSERT INTO sites(name,url,repository,github_token,online,checked_at)
                VALUES('Preserved','https://example.com/','fixture/public','synthetic-cipher',1,'2026-01-01T00:00:00Z')");
            $before = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
            $schema = \Tablo\SqlStatement::checked($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $failed = false;
                try { Database::migrate($db); } catch (\PDOException) { $failed = true; }
                Assert::true($failed);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 4);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $schema);
                Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch(), $before);
                $db->failBefore = null;
            }
            Database::migrate($db);
            $after = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
            Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::fetched($after), 'endpoint_token'), null);
            Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::fetched($after), \Tablo\Tests\Support\FixtureValue::fetched($before)), $before);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), 'synthetic-preserved');
            $db->statements = [];
            Database::migrate($db);
            Assert::same(count(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|ALTER|CREATE|COMMIT)/', $sql) === 1)), 0);
            $db->exec('PRAGMA user_version=' . (Database::CURRENT_SCHEMA_VERSION + 1));
            $db->statements = [];
            $failed = false;
            try { Database::migrate($db); } catch (\RuntimeException) { $failed = true; }
            Assert::true($failed);
            Assert::same(count(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|ALTER|CREATE|COMMIT)/', $sql) === 1)), 0);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), Database::CURRENT_SCHEMA_VERSION + 1);
        }
    }

    #[Test]
    public function injectedExternalKeyAuthenticatesEndpointOnlyStateBeforeAnyMigration(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-external-');
        $sites = null; $vault = null; $db = null;
        try {
            $key = $directory->path . '/key';
            $wrong = $directory->path . '/wrong';
            file_put_contents($key, random_bytes(32));
            file_put_contents($wrong, random_bytes(32));
            $vault = new TokenVault($key, true);
            $path = $directory->path . '/db.sqlite';
            $db = Database::connect($path, $vault);
            $sites = new SiteRepository($db, $vault);
            $sites->save([...UnitFixtures::site(), 'enabled' => '0', 'endpoint_token' => EndpointFixture::tokenA()]);
            // Make a genuine v4-shape partial installation with the additive column already
            // present: wrong selected key must refuse before any transition or WAL changes.
            $db->exec('PRAGMA user_version=4');
            $cipherHash = hash('sha256', \Tablo\Tests\Support\FixtureValue::string(\Tablo\Tests\Support\FixtureValue::row($sites->find(1))['endpoint_token']));
            $vault = null; $sites = null; $db = null;
            $before = hash_file('sha256', $path);
            $failed = false;
            try { Database::connect($path, new TokenVault($wrong, true)); }
            catch (SharedKeyFailure $error) { $failed = $error->getCode() === SharedKeyFailure::UNVERIFIED; unset($error); }
            gc_collect_cycles();
            Assert::true($failed);
            Assert::same(hash_file('sha256', $path), $before);
            $db = new \PDO('sqlite:' . $path);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('PRAGMA user_version'))->fetchColumn(), 4);
            Assert::same(hash('sha256', \Tablo\Tests\Support\FixtureValue::string(\Tablo\SqlStatement::checked($db->query('SELECT endpoint_token FROM sites'))->fetchColumn())), $cipherHash);
            Assert::false(is_file($directory->path . '/github-token.key'));
        } finally { $vault = null; $db = null; $sites = null; $directory->close(); }
    }
}
