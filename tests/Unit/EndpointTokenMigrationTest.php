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
        foreach (['ALTER TABLE sites ADD COLUMN endpoint_token', 'PRAGMA main.user_version = 8', 'COMMIT', null] as $failure) {
            $db = new MigrationPdo(':memory:');
            $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common4.sql'));
            $db->exec("
                INSERT INTO users(id,password_hash) VALUES(1,'synthetic-preserved');
                INSERT INTO sites(name,url,repository,github_token,online,checked_at)
                VALUES('Preserved','https://example.com/','fixture/public','synthetic-cipher',1,'2026-01-01T00:00:00Z')");
            $before = $db->query('SELECT * FROM sites')->fetch();
            $schema = $db->query('SELECT name,sql FROM sqlite_schema ORDER BY name')->fetchAll();
            $db->failBefore = $failure;
            if ($failure !== null) {
                $failed = false;
                try { Database::migrate($db); } catch (\PDOException) { $failed = true; }
                Assert::true($failed);
                Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 4);
                Assert::same($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $schema);
                Assert::same($db->query('SELECT * FROM sites')->fetch(), $before);
                $db->failBefore = null;
            }
            Database::migrate($db);
            $after = $db->query('SELECT * FROM sites')->fetch();
            Assert::same($after['endpoint_token'], null);
            Assert::same(array_intersect_key($after, $before), $before);
            Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), 'synthetic-preserved');
            $db->statements = [];
            Database::migrate($db);
            Assert::same(count(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|ALTER|CREATE|COMMIT)/', $sql) === 1)), 0);
            $db->exec('PRAGMA user_version=9');
            $db->statements = [];
            $failed = false;
            try { Database::migrate($db); } catch (\RuntimeException) { $failed = true; }
            Assert::true($failed);
            Assert::same(count(array_filter($db->statements, static fn (string $sql): bool => preg_match('/^(BEGIN|ALTER|CREATE|COMMIT)/', $sql) === 1)), 0);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 9);
        }
    }

    #[Test]
    public function injectedExternalKeyAuthenticatesEndpointOnlyStateBeforeAnyMigration(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-external-');
        $db = $vault = $sites = null;
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
            $cipherHash = hash('sha256', $sites->find(1)['endpoint_token']);
            $db = $sites = $vault = null;
            $before = hash_file('sha256', $path);
            $failed = false;
            try { Database::connect($path, new TokenVault($wrong, true)); }
            catch (SharedKeyFailure $error) { $failed = $error->getCode() === SharedKeyFailure::UNVERIFIED; unset($error); }
            gc_collect_cycles();
            Assert::true($failed);
            Assert::same(hash_file('sha256', $path), $before);
            $db = new \PDO('sqlite:' . $path);
            Assert::same((int) $db->query('PRAGMA user_version')->fetchColumn(), 4);
            Assert::same(hash('sha256', $db->query('SELECT endpoint_token FROM sites')->fetchColumn()), $cipherHash);
            Assert::false(is_file($directory->path . '/github-token.key'));
        } finally { $sites = $db = $vault = null; $directory->close(); }
    }
}
