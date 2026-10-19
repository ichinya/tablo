<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use PDO;
use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferSequence;
use Tablo\ServerSchema;
use Tablo\TokenVault;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\FixtureCredentials;
use Testo\Assert;
use Testo\Test;

final class EngineTransferTest
{
    #[Test]
    public function completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData(): void
    { engine_transfer_working_data(); }

    #[Test]
    public function nativeAllocationFloorIgnoresCachedStatisticsAndSurvivesAnIndependentAlter(): void
    {
        $fixture = new EngineFixture('transfer-sequence-cache');
        $observer = $fixture->independent(); $maintenance = $fixture->maintenance();
        $sites = new \Tablo\SiteRepository($fixture->database(), $fixture->vault);
        Assert::same($sites->save(\Tablo\Tests\Support\UnitFixtures::site()), 1);
        if (getenv('TABLO_TEST_DB_DRIVER') === 'mysql') { $observer->exec('SET SESSION information_schema_stats_expiry = 86400'); }
        Assert::same(DatabaseTransferSequence::floors($observer)['sites'], 1);
        // Warm a cached observation before the other session advances the allocation.
        if (getenv('TABLO_TEST_DB_DRIVER') === 'mysql') { $observer->exec('SET SESSION information_schema_stats_expiry = 86400'); }
        ServerSchema::rows($observer, "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites'");
        $maintenance->exec('ALTER TABLE sites AUTO_INCREMENT = 7001');
        Assert::same(DatabaseTransferSequence::floors($observer)['sites'], 7000);
        Assert::same($sites->save(\Tablo\Tests\Support\UnitFixtures::site()), 7001);
        Assert::same(DatabaseTransferSequence::floors($observer)['sites'], 7001);
        $fixture->close();
    }

    #[Test]
    public function currentFifteenTableRoundTripPreservesBytesNullIdsKeysAndWatermark(): void
    {
        $fixture = new EngineFixture('transfer');
        $root = EngineSchedule::directory() . '/cases/transfer';
        $source = self::source($root . '/source.sqlite', $fixture->vault);
        $scope = $fixture->vault->credentialScope(FixtureCredentials::value(label: 'transfer-token'));
        $digest = DatabaseTransfer::export($source, $fixture->vault, $root . '/from-sqlite', $root . '/export-scratch.sqlite', offline: true);
        Assert::same(DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/from-sqlite', $root . '/import-scratch.sqlite', offline: true), $digest);
        $server = $fixture->database();
        Assert::same(count(DatabaseTransferManifest::TABLES), 15);
        foreach (DatabaseTransferManifest::TABLES as $table) { Assert::true(DatabaseTransferSequence::scalar($server, 'SELECT COUNT(*) AS value FROM `' . $table . '`') > 0); }
        Assert::same(DatabaseTransferSequence::floors($server)['check_history'], 9000);
        Assert::same(DatabaseTransferSequence::watermark($server), 9000);
        Assert::same(DatabaseTransfer::verify($server, $fixture->vault, $root . '/from-sqlite', $root . '/verify-scratch.sqlite'), $digest);
        $reverse = DatabaseTransfer::export($fixture->independent(), $fixture->vault, $root . '/from-server', $root . '/reverse-scratch.sqlite', offline: true);
        Assert::same(DatabaseTransfer::importSqlite($fixture->vault, $root . '/from-server', $root . '/target-staging.sqlite', $root . '/target.sqlite', offline: true), $reverse);
        $target = new PDO('sqlite:' . $root . '/target.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        Assert::same(DatabaseTransfer::verify($target, $fixture->vault, $root . '/from-sqlite', $root . '/final-scratch.sqlite'), $digest);
        Assert::same(DatabaseTransferSequence::floors($target), ['sites' => 2000, 'git_tokens' => 7000, 'check_history' => 9000]);
        Assert::same($fixture->vault->credentialScope(FixtureCredentials::value(label: 'transfer-token')), $scope);
        Assert::same(ServerSchema::rows($target, 'SELECT run_id, stop_requested, fairness_turn FROM worker_runtime'), [['run_id' => null, 'stop_requested' => 1, 'fairness_turn' => 3]]);
        $fixture->close();
    }

    /** Actual SQLite production DDL, with synthetic rows in every current domain table. */
    public static function source(string $path, TokenVault $vault): PDO
    {
        $db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys = ON');
        \Tablo\SchemaReference::reference(10, ['endpoint' => true], $db);
        $db->exec('PRAGMA main.user_version = 10');
        self::credentials($db, $vault); self::site($db, $vault); self::projections($db, $vault);
        $db->exec("UPDATE sqlite_sequence SET seq = CASE name WHEN 'sites' THEN 2000 WHEN 'git_tokens' THEN 7000 WHEN 'check_history' THEN 9000 ELSE seq END");
        return $db;
    }

    private static function credentials(PDO $db, TokenVault $vault): void
    {
        $hash = \Tablo\PasswordService::hash(FixtureCredentials::syntheticPassword(), FixtureCredentials::syntheticPassword());
        ServerSchema::rows($db, 'INSERT INTO users (id,password_hash,created_at) VALUES (1,?,?)', [$hash, '0001-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO git_tokens (id,name,provider,encrypted_token,created_at,updated_at) VALUES (7,?,?,?,?,?)',
            ['Café ', 'github', $vault->encrypt(FixtureCredentials::value(label: 'transfer-token')), '0099-12-31T23:59:59Z', '0100-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO read_api_token (id,secret_hash,administrator_fingerprint,created_at) VALUES (1,?,?,?)',
            [hash('sha256', FixtureCredentials::value(label: 'transfer-api')), \Tablo\AdministratorFingerprint::fingerprint($hash), '0001-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO login_limits (`key`,failures,window_start) VALUES (?,3,4)', [hash('sha256', data: 'synthetic-address')]);
        ServerSchema::rows($db, 'INSERT INTO github_cooldowns (scope,resource,eligible_at) VALUES (?,?,?)',
            [$vault->credentialScope(FixtureCredentials::value(label: 'transfer-token')), 'core', 2_147_483_648]);
    }

    private static function site(PDO $db, TokenVault $vault): void
    {
        ServerSchema::rows($db, 'INSERT INTO sites (id,name,url,repository,git_token_id,github_token,endpoint_token,config_revision,checked_at) VALUES (20,?,?,?,7,?,?,6,?)',
            ['Сайт 🧪 ', 'https://synthetic.invalid', 'synthetic/repository', $vault->encrypt(FixtureCredentials::value(label: 'transfer-legacy')),
                $vault->encrypt(FixtureCredentials::value(label: 'transfer-endpoint')), '0001-01-01T00:00:00Z']);
        $db->exec('UPDATE installation_settings SET check_interval_minutes = 17, session_idle_timeout_minutes = 42');
        $db->exec('UPDATE worker_runtime SET stop_requested = 1, fairness_turn = 3');
        $db->exec('INSERT INTO worker_progress (site_id,config_revision,latest_release,latest_commit,open_issues,open_prs) VALUES (20,6,3,5,7,9)');
        $db->exec("INSERT INTO check_history (id,site_id,config_revision,checked_at,online,version_status) VALUES (100,20,6,'0001-01-01T00:00:00Z',0,'skipped')");
        $db->exec("INSERT INTO incidents (id,site_id,config_revision,opened_at,uncertain,clock_invalid) VALUES (100,20,6,'0001-01-01T00:00:00Z',1,0)");
        $db->exec("INSERT INTO incident_checkpoints (site_id,last_history_id,config_revision,watermark) VALUES (20,100,6,'0001-01-01T00:00:00Z')");
    }

    private static function projections(PDO $db, TokenVault $vault): void
    {
        ServerSchema::rows($db, 'UPDATE notification_settings SET revision = 2, activation_id = 1234, endpoint_cipher = ?, bearer_cipher = ? WHERE id = 1',
            [$vault->encrypt('https://synthetic.invalid/webhook'), $vault->encrypt(FixtureCredentials::value(label: 'transfer-bearer'))]);
        ServerSchema::rows($db, 'INSERT INTO notification_checkpoints (site_id,last_history_id,config_revision,state) VALUES (20,100,6,?)', ['{"synthetic":"unchanged bytes "}']);
        ServerSchema::rows($db, 'INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,created_at,expires_at,due_at,status,attempts,claim,lease_until,acknowledged_at) VALUES (20,?,?,100,6,2,?,?,1,2,1,?,2,?,2,3)',
            ['unavailable', 'synthetic-event ', '{"version":1}', $vault->encrypt(FixtureCredentials::value(label: 'transfer-private')), 'sent', "completed-claim \0\xff"]);
    }
}

/** Fixture generation is separate from the child's measured complete transfer, with no transfer warmup. */
function engine_transfer_working_data(): void
{
    $fixture = new EngineFixture('transfer-working-data');
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-transfer-memory-');
    try {
        $root = EngineSchedule::directory() . '/cases/transfer-working-data';
        $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault);
        engine_transfer_working_fixture($source);
        Assert::same(DatabaseTransferSequence::scalar($source, 'SELECT COUNT(*) AS value FROM sites'), 128);
        Assert::same(DatabaseTransferSequence::scalar($source, 'SELECT SUM(length(CAST(last_error AS BLOB))) AS value FROM sites'), 11_520_000);
        $source = null;
        $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, dirname(__DIR__) . '/server-child.php', $fixture->case, 'transfer-working-data'],
            $directory, $fixture->environment(), new \Tablo\Tests\Support\CommandOptions(timeout: 6));
        Assert::same([$child['exit_code'], $child['stderr']], [0, '']);
        $result = \Tablo\JsonRecord::decode($child['stdout']);
        Assert::same([$result['status'] ?? null, $result['tables'] ?? null, $result['payloadBytes'] ?? null], ['passed', 15, 11_520_000]);
        Assert::same($result['nativeBufferedSource'] ?? null, false);
        $used = \Tablo\SqlColumn::integer($result, 'peakPhpWorkingBytes');
        Assert::true($used > 0); Assert::true($used <= 2_097_152, 'Complete measured helper, including cold transfer metadata, exceeds2MiB');
        Assert::same($result['allTypedDigestsPreserved'] ?? null, true);
        \Tablo\Tests\Support\SessionEvidence::record('transfer-working-memory', ['status' => 'passed', 'payloadBytes' => 11_520_000,
            'tables' => 15, 'peakPhpWorkingBytes' => $used, 'nativeBufferedSource' => false,
            'baselinePhpBytes' => \Tablo\SqlColumn::integer($result, 'baselinePhpBytes'), 'peakPhpBytes' => \Tablo\SqlColumn::integer($result, 'peakPhpBytes'),
            'metricScope' => \Tablo\SqlColumn::string($result, 'metricScope')]);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $fixture->close(); $directory->close(); }
}

function engine_transfer_working_fixture(PDO $source): void
{
    $columns = [];
    foreach (DatabaseTransferManifest::tables() as $table) {
        if ($table['name'] !== 'sites') { continue; }
        $columns = array_column($table['columns'], 'name');
    }
    Assert::true(in_array('last_error', $columns, strict: true));
    ServerSchema::rows($source, 'UPDATE sites SET last_error = ? WHERE id = 20', [str_repeat('w', times: 90_000)]);
    $quoted = array_map(static fn (string $name): string => '`' . $name . '`', $columns);
    $selection = array_map(static fn (string $name): string => $name === 'id' ? '?' : '`' . $name . '`', $columns);
    $sql = 'INSERT INTO sites (' . implode(',', $quoted) . ') SELECT ' . implode(',', $selection) . ' FROM sites WHERE id = 20';
    for ($index = 1; $index < 128; $index++) { ServerSchema::rows($source, $sql, [20 + $index]); }
}
