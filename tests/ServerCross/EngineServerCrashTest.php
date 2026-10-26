<?php
declare(strict_types=1);

namespace Tablo\Tests\ServerCross;

use Tablo\DatabaseFailure;
use Tablo\DatabaseSession;
use Tablo\JsonEncoding;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\ServerLock;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\SqlColumn;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

/** The cell performs the real crash outside the unchanged eight-second verification budget. */
final class EngineServerCrashTest
{
    #[Test]
    public function mysqlCrashAfterDdlRetainsIntentAndResumesTheRecognizedInstallation(): void
    { engine_server_crash_verify('mysql', case: 'server-crash-ddl', boundary: 'after-ddl', tables: 4); }

    #[Test]
    public function mysqlCrashBeforePublicationRetainsAllDdlWithoutAdvertisingReady(): void
    { engine_server_crash_verify('mysql', case: 'server-crash-publication', boundary: 'before-publication', tables: 18); }

    #[Test]
    public function mariadbCrashAfterDdlRetainsIntentAndResumesTheRecognizedInstallation(): void
    { engine_server_crash_verify('mariadb', case: 'server-crash-ddl', boundary: 'after-ddl', tables: 4); }

    #[Test]
    public function mariadbCrashBeforePublicationRetainsAllDdlWithoutAdvertisingReady(): void
    { engine_server_crash_verify('mariadb', case: 'server-crash-publication', boundary: 'before-publication', tables: 18); }

    /** Retain exact committed state and journal, including private installation identity, by digest only. */
    public static function snapshotDigest(\PDO $db): string
    {
        return hash('sha256', JsonEncoding::encode(['objects' => ServerSchema::objects($db),
            'state' => ServerSchema::rows($db, 'SELECT * FROM tablo_database_state ORDER BY id'),
            'journal' => ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal ORDER BY plan,step')]));
    }
}

    function engine_server_crash_verify(string $engine, string $case, string $boundary, int $tables): void
    {
        Assert::same([getenv('TABLO_TEST_DB_DRIVER'), PHP_MAJOR_VERSION, PHP_MINOR_VERSION], ['mysql', 8, 5]);
        $peer = $engine === 'mysql' ? null : 'mariadb';
        $record = EngineSchedule::crashReceipt($case, $peer);
        engine_server_crash_lifecycle($record, $engine, $boundary);
        $fixture = new EngineFixture($case, $peer);
        try {
            $db = $fixture->maintenance();
            Assert::same(DatabaseSession::get($db)?->configuration->engine, $engine);
            Assert::same(count(ServerSchema::objects($db)), $tables);
            Assert::same(EngineServerCrashTest::snapshotDigest($db), SqlColumn::string($record, 'beforeSnapshotSha256'));
            Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 0, 'phase' => 'initializing']);
            $ssl = ServerSchema::rows($db, "SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            Assert::false(SqlColumn::string($ssl[0] ?? [], 'Value') === '');
            $receipt = EngineSchedule::receipt($case, $peer);
            $digest = RequiredFile::digest($receipt);
            Assert::same($digest, SqlColumn::string($record, 'bootstrapReceiptSha256'));
            engine_server_crash_journal($fixture, $db, $tables);
            engine_server_crash_refuse_normal_admission($fixture);
            $lock = new ServerLock();
            Assert::true($lock->acquire($db, 'migration'));
            try { $lock->assertOwned(); } finally { $lock->close(); }
            (new ServerMigration($db, $fixture->vault))->initialize($receipt);
            Assert::same(RequiredFile::digest($receipt), $digest);
            Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
            Assert::same(count(ServerSchema::objects($db)), 18);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $db), 18);
            ServerSchema::validateLayout($db);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase <> 'done' OR failure_category IS NOT NULL", $db), 0);
            $readiness = [];
            foreach (['writer', 'reader'] as $role) {
                $connection = $fixture->independent($role);
                Assert::same($fixture->scalar('SELECT 1', $connection), 1);
                $readiness[$role] = ['role' => DatabaseSession::get($connection)?->configuration->role,
                    'state' => ServerSchema::rows($connection, 'SELECT id,format,logical_version,phase FROM tablo_database_state')];
                unset($connection);
            }
            Assert::same($readiness, [
                'writer' => ['role' => 'writer', 'state' => [['id' => 1, 'format' => 1, 'logical_version' => 10, 'phase' => 'ready']]],
                'reader' => ['role' => 'reader', 'state' => [['id' => 1, 'format' => 1, 'logical_version' => 10, 'phase' => 'ready']]],
            ]);
        } finally { $fixture->close(); }
    }

    function engine_server_crash_lifecycle(array $record, string $engine, string $boundary): void
    {
        $pins = JsonRecord::decode(RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/deploy/database-engines.json')));
        $pin = JsonRecord::checked($pins[$engine] ?? null);
        Assert::same($record['boundary'] ?? null, $boundary);
        Assert::same($record['imageReference'] ?? null, SqlColumn::string($pin, 'image'));
        $container = SqlColumn::string($record, 'containerId'); $image = SqlColumn::string($record, 'imageId');
        Assert::same(preg_match('/\A[0-9a-f]{64}\z/D', $container), 1);
        Assert::same(preg_match('/\Asha256:[0-9a-f]{64}\z/D', $image), 1);
        Assert::same($record['kill'] ?? null, ['signal' => 'SIGKILL', 'exitCode' => 137, 'observedStopped' => true]);
        $restart = JsonRecord::checked($record['restart'] ?? null);
        Assert::same([$restart['containerId'] ?? null, $restart['imageId'] ?? null, $restart['volume'] ?? null],
            [$container, $image, SqlColumn::string($record, 'volume')]);
        Assert::same([$restart['observedRunning'] ?? null, $restart['freshAuthenticatedTlsPdo'] ?? null], [true, true]);
        $actor = JsonRecord::checked($record['actor'] ?? null);
        Assert::same([$actor['closed'] ?? null, $actor['exitCode'] ?? null, $actor['boundaryObserved'] ?? null], [true, 86, true]);
        Assert::same(preg_match('/\A[0-9a-f]{64}\z/D', SqlColumn::string($record, 'beforeSnapshotSha256')), 1);
    }

    function engine_server_crash_journal(EngineFixture $fixture, \PDO $db, int $tables): void
    {
        if ($tables === 4) {
            Assert::same(ServerSchema::rows($db, 'SELECT phase,failure_category FROM tablo_migration_journal WHERE step = 3'),
                [['phase' => 'intent', 'failure_category' => null]]);
            return;
        }
        Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $db), 18);
    }

    function engine_server_crash_refuse_normal_admission(EngineFixture $fixture): void
    {
        foreach (['writer', 'reader'] as $role) {
            $caught = null;
            try { $fixture->independent($role); } catch (DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
        }
    }
