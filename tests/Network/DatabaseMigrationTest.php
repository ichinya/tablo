<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\Tests\Support\MigrationPdo;
use Tablo\Tests\Support\DatabaseConnectSchedule;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseMigrationTest extends DatabaseMigrationFixtureCase
{

    #[Test]
    public function retriesRealWalBusyAfterCompetingMigratorFinishes(): void
    {
        foreach (['fresh', 'legacy', 'version-one', 'version-four'] as $schema) {
            $directory = new TemporaryDirectory('tablo-connect-wal-race-');
            try {
                $path = $directory->path . '/test.sqlite';
                if ($schema !== 'fresh' && $schema !== 'version-four') { self::legacy($path, $schema === 'version-one' ? \Tablo\Tests\Support\SchemaFixture::VersionOne : \Tablo\Tests\Support\SchemaFixture::Historic); }
                if ($schema === 'version-four') {
                    $db = new MigrationPdo($path);
                    $db->exec(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common4.sql'));
                    $db->exec("INSERT INTO users(id,password_hash) VALUES(1,'synthetic-hash');
                        INSERT INTO sites(name,url,repository,github_token) VALUES('Historical','https://example.test/','fixture/public','synthetic-ciphertext')");
                    $before = $db->query('SELECT * FROM sites')->fetch();
                    $columns = $db->query('PRAGMA table_info(sites)')->fetchAll();
                    unset($db);
                }
                $schedule = new DatabaseConnectSchedule($directory);
                $result = $schedule->race($path);
                WalRaceAssertions::observed($result);
                $db = Database::connect($path);
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
                Assert::same($db->query('PRAGMA foreign_key_check')->fetchAll(), []);
                if ($schema !== 'fresh') {
                    Assert::same($db->query('SELECT password_hash FROM users')->fetchColumn(), 'synthetic-hash');
                    Assert::same($db->query('SELECT github_token FROM sites')->fetchColumn(), 'synthetic-ciphertext');
                }
                if ($schema === 'version-four') {
                    $after = $db->query('SELECT * FROM sites')->fetch();
                    Assert::same($after['endpoint_token'], null);
                    Assert::same(array_intersect_key($after, $before), $before);
                    Assert::same(array_slice($db->query('PRAGMA table_info(sites)')->fetchAll(), offset: 0, length: count($columns)), $columns);
                    $catalog = $db->query('SELECT name,sql FROM sqlite_schema ORDER BY name')->fetchAll();
                    Database::migrate($db);
                    Assert::same($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name')->fetchAll(), $catalog);
                    Assert::same($db->query('SELECT * FROM sites')->fetch(), $after);
                }
            } finally { unset($db); $directory->close(); }
        }
    }



    #[Test]
    public function currentDeleteWalNegotiationReleasesOrExhaustsOneBudget(): void
    {
        foreach ([true, false] as $release) {
            $directory = new TemporaryDirectory('tablo-connect-wal-current-');
            try {
                $path = $directory->path . '/test.sqlite';
                $db = Database::connect($path);
                $db->exec('PRAGMA journal_mode = DELETE');
                unset($db);
                $result = (new DatabaseConnectSchedule($directory))->current($path, $release ? \Tablo\Tests\Support\WalHolderPolicy::ReleaseBeforeRetry : \Tablo\Tests\Support\WalHolderPolicy::HoldThroughTimeout);
                Assert::same($result['holder']['code'], 0);
                Assert::same($result['A']['code'], $release ? 0 : 1);
                switch (true) {
case ($release):
 Assert::same($result['A']['data']['timeout'], 5000);
    break;
default:

                    Assert::same($result['A']['data']['sqliteCode'], 5);
                    Assert::true($result['A']['data']['elapsedMs'] >= 4900 && $result['A']['data']['elapsedMs'] < 8000,
                        'Unexpected cooperative WAL budget under the test scheduler');

    break;
}
                $events = $result['A']['events'];
                $phase = array_values(array_filter($events, static fn (array $event): bool => $event['stage'] === 'phase-start'))[0];
                foreach ($events as $event) {
                    if (!($event['stage'] === 'attempt-start')) { continue; }
 Assert::true($event['ns'] < $phase['deadline'], 'WAL attempt began after deadline');
                }
                $db = new \PDO('sqlite:' . $path);
                Assert::same($db->query('PRAGMA journal_mode')->fetchColumn(), $release ? 'wal' : 'delete');
                Assert::same($db->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
            } finally { unset($db); $directory->close(); }
        }
    }
}
