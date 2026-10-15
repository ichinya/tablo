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
                $historical = $schema === 'version-four' ? self::versionFour($path) : null;
                $schedule = new DatabaseConnectSchedule($directory);
                $result = $schedule->race($path);
                WalRaceAssertions::observed($result);
                $db = Database::connect($path);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA integrity_check'))->fetchColumn(), 'ok');
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA foreign_key_check'))->fetchAll(), []);
                if ($schema !== 'fresh') {
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), 'synthetic-hash');
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT github_token FROM sites'))->fetchColumn(), 'synthetic-ciphertext');
                }
                if ($historical !== null) {
                    [$before, $columns] = $historical;
                    $after = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
                    Assert::same(\Tablo\Tests\Support\FixtureValue::at(\Tablo\Tests\Support\FixtureValue::fetched($after), 'endpoint_token'), null);
                    Assert::same(array_intersect_key(\Tablo\Tests\Support\FixtureValue::fetched($after), \Tablo\Tests\Support\FixtureValue::fetched($before)), $before);
                    Assert::same(array_slice(\Tablo\SqlStatement::checked($db->query('PRAGMA table_info(sites)'))->fetchAll(), offset: 0, length: count($columns)), $columns);
                    $catalog = \Tablo\SqlStatement::checked($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name'))->fetchAll();
                    Database::migrate($db);
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT name,sql FROM sqlite_schema ORDER BY name'))->fetchAll(), $catalog);
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch(), $after);
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
 Assert::same(\Tablo\Tests\Support\ScheduleObservation::success($result['A']['data'])['timeout'], 5000);
    break;
default:

                    Assert::same(\Tablo\Tests\Support\ScheduleObservation::failure($result['A']['data'])['sqliteCode'], 5);
                    Assert::true($result['A']['data']['elapsedMs'] >= 4900 && $result['A']['data']['elapsedMs'] < 8000,
                        'Unexpected cooperative WAL budget under the test scheduler');

    break;
}
                $events = $result['A']['events'];
                $phase = \Tablo\Tests\Support\ScheduleObservation::phase(\Tablo\Tests\Support\FixtureValue::at(array_values(array_filter($events, static fn (array $event): bool => $event['stage'] === 'phase-start')), 0));
                foreach ($events as $event) {
                    if (!($event['stage'] === 'attempt-start')) { continue; }
 Assert::true($event['ns'] < $phase['deadline'], 'WAL attempt began after deadline');
                }
                $db = new \PDO('sqlite:' . $path);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA journal_mode'))->fetchColumn(), $release ? 'wal' : 'delete');
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA integrity_check'))->fetchColumn(), 'ok');
            } finally { unset($db); $directory->close(); }
        }
    }
    /** @return list{array|false, array} */
    private static function versionFour(string $path): array
    {
        $db = new MigrationPdo($path);
        $db->exec(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/historical-schema/common4.sql')));
        $db->exec("INSERT INTO users(id,password_hash) VALUES(1,'synthetic-hash');
            INSERT INTO sites(name,url,repository,github_token) VALUES('Historical','https://example.test/','fixture/public','synthetic-ciphertext')");
        $before = \Tablo\Tests\Support\FixtureValue::databaseRow(\Tablo\SqlStatement::checked($db->query('SELECT * FROM sites'))->fetch());
        $columns = \Tablo\SqlStatement::checked($db->query('PRAGMA table_info(sites)'))->fetchAll();
        unset($db);
        return [$before, $columns];
    }
}
