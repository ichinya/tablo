<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use PDO;
use Tablo\DatabaseTransferSequence;
use Tablo\JsonRecord;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\SqlColumn;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureCredentials;
use Testo\Assert;
use Testo\Test;

/** Standalone17-object synthetic logical9, never current bootstrap followed by dropping the API table. */
final class EngineMigrationPreviousTest
{
    #[Test]
    public function actualNineToTenPreservesEveryPredecessorRowCipherSettingAndAllocation(): void
    {
        $fixture = new EngineFixture('migration-previous'); $db = $fixture->maintenance();
        $tables = self::materialize($fixture, $db); $before = self::snapshot($db, $tables);
        $migration = new ServerMigration($db, $fixture->vault);
        $migration->migrate();
        Assert::same($migration->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM read_api_token', $db), 0);
        Assert::same(self::snapshot($db, $tables), $before);
        Assert::same(DatabaseTransferSequence::floors($db), ['sites' => 2000, 'git_tokens' => 7000, 'check_history' => 9000]);
        Assert::same(DatabaseTransferSequence::watermark($db), 9000);
        $state = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        $journal = ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal');
        $migration->migrate();
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $state);
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'), $journal);
        Assert::same(self::snapshot($db, $tables), $before); $fixture->close();
    }

    #[Test]
    public function interruptedNineToTenKeepsCommittedApiDdlNonReadyUntilExactResume(): void
    {
        $fixture = new EngineFixture('migration-previous-interrupted'); $db = $fixture->maintenance();
        $tables = self::materialize($fixture, $db); $before = self::snapshot($db, $tables);
        $failure = new \RuntimeException('synthetic nine-to-ten interruption');
        $migration = new ServerMigration($db, $fixture->vault, static function (string $phase, int $step) use ($failure): void {
            if ([$phase, $step] === ['after-ddl', 0]) { throw $failure; }
        });
        $caught = null;
        try { $migration->migrate(); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught, $failure); Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same($migration->status(), ['format' => 1, 'logical_version' => 9, 'phase' => 'migrating']);
        Assert::same(ServerSchema::rows($db, 'SELECT predecessor,target,phase FROM tablo_migration_journal'), [['predecessor' => 9, 'target' => 10, 'phase' => 'intent']]);
        Assert::same(self::snapshot($db, $tables), $before);
        $resumed = new ServerMigration($fixture->maintenance(), $fixture->vault); $resumed->migrate();
        Assert::same($resumed->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM read_api_token', $db), 0);
        Assert::same(self::snapshot($db, $tables), $before);
        Assert::same(DatabaseTransferSequence::floors($db), ['sites' => 2000, 'git_tokens' => 7000, 'check_history' => 9000]);
        $fixture->close();
    }

    #[Test]
    public function nativeCreateDenialRetainsIntentAndRowsUntilTheRealMigratorResumes(): void
    {
        // A dedicated test actor can journal/mark phase, but cannot CREATE/ALTER.
        $fixture = new EngineFixture('migration-previous-denied-ddl'); $db = $fixture->maintenance();
        $tables = self::materialize($fixture, $db); $before = self::snapshot($db, $tables);
        $environment = $fixture->environment();
        $environment['TABLO_DB_MIGRATE_USER'] = SqlColumn::string($environment, 'TABLO_TEST_DB_DENIED_DDL_USER');
        $environment['TABLO_DB_MIGRATE_PASSWORD'] = SqlColumn::string($environment, 'TABLO_TEST_DB_DENIED_DDL_PASSWORD');
        $restricted = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment('migrator', $environment), mode: 'maintenance');
        Assert::same($restricted->exec('UPDATE tablo_migration_journal SET phase = phase WHERE step = -1'), 0);
        Assert::same($restricted->exec('UPDATE tablo_database_state SET phase = phase WHERE id = 0'), 0);
        $native = null;
        try { $restricted->exec('CREATE TABLE synthetic_denied_ddl (id BIGINT NOT NULL PRIMARY KEY) ENGINE=InnoDB'); }
        catch (\PDOException $error) { $native = \Tablo\DatabaseInteger::checked($error->errorInfo[1] ?? null); }
        Assert::same($native, 1142);
        $points = [];
        $migration = new ServerMigration($restricted, $fixture->vault, static function (string $phase, int $step) use (&$points): void { $points[] = [$phase, $step]; });
        $caught = null;
        try { $migration->migrate(); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'unavailable'); Assert::same($caught?->getPrevious(), null);
        Assert::same($points, [['before-ddl', 0]]);
        Assert::same($migration->status(), ['format' => 1, 'logical_version' => 9, 'phase' => 'migrating']);
        Assert::same(count(ServerSchema::objects($db)), 17);
        Assert::same(ServerSchema::rows($db, 'SELECT predecessor,target,phase FROM tablo_migration_journal'), [['predecessor' => 9, 'target' => 10, 'phase' => 'intent']]);
        Assert::same(self::snapshot($db, $tables), $before);
        $resumed = new ServerMigration($fixture->maintenance(), $fixture->vault); $resumed->migrate();
        Assert::same($resumed->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM read_api_token', $db), 0);
        Assert::same(self::snapshot($db, $tables), $before); $fixture->close();
    }

    #[Test]
    public function committedPhaseWithLostAcknowledgementStaysUncertainDespiteRollbackFailureAndResumes(): void
    { engine_migration_uncertain_phase(); }

    #[Test]
    public function committedPublicationWithLostAcknowledgementStaysUncertainAndFreshMigrationDoesNotReplay(): void
    { engine_migration_uncertain_publication(); }

    #[Test]
    public function nativePhaseConstraintBeforeCommitRollsBackAndKeepsItsSanitizedCategory(): void
    { engine_migration_phase_constraint(); }

    /** @return list<array> */
    public static function materialize(EngineFixture $fixture, PDO $db): array
    {
        Assert::same(ServerSchema::objects($db), []);
        $engine = \Tablo\Tests\Support\FixtureValue::string(getenv('TABLO_TEST_DB_DRIVER'));
        $header = engine_synthetic_logical9_fixture($engine);
        $tables = JsonRecord::checked($header['tables'] ?? null);
        Assert::same([$header['schema'] ?? null, $header['engine'] ?? null, $header['metadataFormat'] ?? null, $header['logicalVersion'] ?? null, $header['domainTables'] ?? null, count($tables)],
            ['tablo/synthetic-logical9-fixture/v1', $engine, 1, 9, 14, 17]);
        $checksum = SqlColumn::string($header, 'layoutChecksum'); Assert::same($checksum, ServerSchema::checksum($db, version: 9));
        engine_schema_checksum_streaming_contract();
        engine_schema_checksum_public_equivalence($db);
        $declarations = []; $names = [];
        foreach (array_keys($tables) as $index) {
            $table = JsonRecord::checked($tables[$index] ?? null); $sql = SqlColumn::string($table, 'sql');
            Assert::same(hash('sha256', $sql), SqlColumn::string($table, 'sha256')); $db->exec($sql); $declarations[] = $table;
            $names[] = SqlColumn::string($table, 'name');
        }
        Assert::same(array_slice($names, offset: 0, length: 3), ['tablo_migration_journal', 'tablo_database_state', 'tablo_writer_guard']);
        Assert::same(count(ServerSchema::objects($db)), 17); Assert::false(in_array('read_api_token', ServerSchema::objects($db), strict: true));
        self::seed($fixture, $db, $checksum); ServerSchema::validateLayout($db, version: 9);
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 9, 'phase' => 'ready']);
        return $declarations;
    }

    private static function seed(EngineFixture $fixture, PDO $db, string $checksum): void
    {
        $installation = bin2hex(random_bytes(16)); $vault = $fixture->vault;
        ServerSchema::rows($db, "INSERT INTO tablo_database_state (id,format,logical_version,layout_checksum,phase,installation_id,key_verifier,committed_history_high_watermark) VALUES (1,1,9,?,'ready',?,?,9000)",
            [$checksum, $installation, $vault->serverVerifier($installation)]);
        $db->exec('INSERT INTO tablo_writer_guard (id) VALUES (1)');
        $password = FixtureCredentials::syntheticPassword(); $hash = \Tablo\PasswordService::hash($password, $password);
        ServerSchema::rows($db, 'INSERT INTO users (id,password_hash,created_at) VALUES (1,?,?)', [$hash, '0001-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO git_tokens (id,name,provider,encrypted_token,created_at,updated_at) VALUES (7,?,?,?,?,?)',
            ['Café ', 'github', $vault->encrypt(FixtureCredentials::value(label: 'previous-token')), '0099-12-31T23:59:59Z', '0100-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO sites (id,name,url,repository,git_token_id,github_token,endpoint_token,config_revision,created_at,updated_at,checked_at) VALUES (20,?,?,?,7,?,?,6,?,?,?)',
            ['Сайт 🧪 ', 'https://synthetic.invalid', 'synthetic/previous', $vault->encrypt(FixtureCredentials::value(label: 'previous-legacy')),
                $vault->encrypt(FixtureCredentials::value(label: 'previous-endpoint')), '0001-01-01T00:00:00Z', '0099-12-31T23:59:59Z', '0001-01-01T00:00:00Z']);
        ServerSchema::rows($db, 'INSERT INTO login_limits (`key`,failures,window_start) VALUES (?,3,4)', [hash('sha256', data: 'synthetic-previous-address')]);
        ServerSchema::rows($db, 'INSERT INTO github_cooldowns (scope,resource,eligible_at) VALUES (?,?,?)', [$vault->credentialScope(FixtureCredentials::value(label: 'previous-token')), 'core', 2_147_483_648]);
        $db->exec('INSERT INTO installation_settings (id,check_interval_minutes,session_idle_timeout_minutes) VALUES (1,17,42)');
        $db->exec('INSERT INTO worker_runtime (id,stop_requested,fairness_turn) VALUES (1,1,3)');
        $db->exec('INSERT INTO worker_progress (site_id,config_revision,latest_release,latest_commit,open_issues,open_prs) VALUES (20,6,3,5,7,9)');
        $db->exec("INSERT INTO check_history (id,site_id,config_revision,checked_at,online,version_status) VALUES (100,20,6,'0001-01-01T00:00:00Z',0,'skipped')");
        $db->exec("INSERT INTO incidents (id,site_id,config_revision,opened_at,uncertain,clock_invalid) VALUES (100,20,6,'0001-01-01T00:00:00Z',1,0)");
        $db->exec("INSERT INTO incident_checkpoints (site_id,last_history_id,config_revision,watermark) VALUES (20,100,6,'0001-01-01T00:00:00Z')");
        ServerSchema::rows($db, 'INSERT INTO notification_settings (id,installation_id,revision,activation_id,endpoint_cipher,bearer_cipher) VALUES (1,?,2,1234,?,?)',
            [$installation, $vault->encrypt('https://synthetic.invalid/webhook'), $vault->encrypt(FixtureCredentials::value(label: 'previous-bearer'))]);
        ServerSchema::rows($db, 'INSERT INTO notification_checkpoints (site_id,last_history_id,config_revision,state) VALUES (20,100,6,?)', ['{"synthetic":"previous bytes "}']);
        ServerSchema::rows($db, 'INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,created_at,expires_at,due_at,status,attempts,claim,lease_until,acknowledged_at) VALUES (20,?,?,100,6,2,?,?,1,2,1,?,2,?,2,3)',
            ['unavailable', 'synthetic-previous-event ', '{"version":1}', $vault->encrypt(FixtureCredentials::value(label: 'previous-private')), 'sent', 'completed-previous-claim ']);
        $db->exec('ALTER TABLE sites AUTO_INCREMENT = 2001'); $db->exec('ALTER TABLE git_tokens AUTO_INCREMENT = 7001'); $db->exec('ALTER TABLE check_history AUTO_INCREMENT = 9001');
    }

    /** @param list<array> $tables */
    public static function snapshot(PDO $db, array $tables): array
    {
        $snapshot = [];
        // The frozen declaration has exactly three metadata objects followed by fourteen domain objects.
        foreach (array_slice($tables, offset: 3) as $table) {
            $name = SqlColumn::string($table, 'name');
            $snapshot[$name] = ServerSchema::rows($db, 'SELECT * FROM `' . $name . '` ORDER BY 1');
            Assert::true(count($snapshot[$name]) > 0, $name);
        }
        Assert::same(count($snapshot), 14); return $snapshot;
    }
}

function engine_schema_checksum_public_equivalence(PDO $db): void
{
    $layout = ServerSchema::layout($db);
    $golden = engine_schema_descriptor_golden($layout['engine']);
    Assert::same($layout, $golden);
    foreach ([9, 10] as $version) {
        $reference = hash('sha256', \Tablo\JsonEncoding::encode(array_values(array_filter($golden['tables'],
            static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'))));
        Assert::same($reference, engine_schema_descriptor_digest($layout['engine'], $version));
        Assert::same(ServerSchema::checksum($db, $version), $reference);
    }
    Assert::same(ServerSchema::checksum($db), ServerSchema::checksum($db, version: 10));
}

/** Pure coverage invokes the same private algorithm used by the public checksum. */
function engine_schema_checksum_streaming_contract(): void
{
    $method = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, '_checksumTables');
    foreach ([[], [[]], [['name' => 'one']], [
        ['name' => 'first', 'values' => [null, false, true, 0, 1, -1, 1.0, 1.5, -0.0, '1', '', [], ['nested' => []]]],
        ['name' => 'second', 'payload' => "quote\" slash/ backslash\\ control\0\r\n\t Café\u{2028}"],
    ], array_values([9 => ['name' => 'first'], 2 => ['name' => 'second', 'keys' => [3 => 'three', 1 => 'one']]])] as $tables) {
        engine_schema_checksum_equivalent($method, $tables);
    }
    engine_schema_checksum_chunks($method);
    engine_schema_checksum_depth($method);
    engine_schema_checksum_encoding_failures($method);
    engine_schema_descriptor_records_contract();
}

/** Frozen old JSON is a test oracle only; the application never opens this directory.
 * @return \Tablo\ServerSchema::Layout */
function engine_schema_descriptor_golden(string $engine): array
{
    $stem = match ($engine) { 'mysql' => 'mysql84', 'mariadb' => 'mariadb114', default => throw new \RuntimeException('Unknown descriptor fixture.') };
    /** @var \Tablo\ServerSchema::Layout $layout */
    $layout = json_decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__) . '/fixtures/server-layouts/v10/' . $stem . '-layout.json')),
        associative: true, flags: JSON_THROW_ON_ERROR);
    Assert::same($layout['engine'], $engine);
    return $layout;
}

function engine_schema_descriptor_digest(string $engine, int $version): string
{
    $digests = engine_schema_descriptor_digests($engine);
    if (!array_key_exists($version, $digests)) { throw new \RuntimeException('Missing immutable descriptor digest.'); }
    return $digests[$version];
}

/** @return array{9: string, 10: string} */
function engine_schema_descriptor_digests(string $engine): array
{
    return match ($engine) {
        'mysql' => [9 => '4aea292a93b941e03fde9425fe9171d5aed0f640749d6db5264be27737c5402a', 10 => 'eb552f693d4a62a35471699b5028e0071400981e0bffcf04cfc236e904fb870c'],
        'mariadb' => [9 => '94a0924d22edc7a4beed0df471a22d9d155241fc5d8b46508719a6b0f47d7eea', 10 => 'ee419c2eca38b465fcc40416e6e749e09043bd5e623c1dab0513ceea0b2f9893'],
        default => throw new \RuntimeException('Unknown descriptor digest fixture.'),
    };
}

/** Pure codec/integrity contracts retain the existing test identities and never open PDO. */
function engine_schema_descriptor_records_contract(): void
{
    $loader = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, 'sourceLayout');
    $checksum = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, '_checksumTables');
    $sourceChecksum = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, 'sourceChecksum');
    Assert::true(stream_wrapper_register('tabloschemafixture', EngineSchemaDescriptorStream::class));
    try {
        engine_schema_descriptor_engine_selection();
        foreach (['mysql' => ['mysql84', 'c38753d3032251af4cfaff02729aaef222c34fb929f690c1fc45964d9400a01e'],
            'mariadb' => ['mariadb114', 'ed9fb39e4c0a1c74461d0c19af6ef6cae5db581d7531a58e5e6ec4e5a776c704']] as $engine => [$stem, $rawHash]) {
            $root = dirname(__DIR__, levels: 2);
            Assert::same(hash_file('sha256', $root . '/tests/fixtures/server-layouts/v10/' . $stem . '-layout.json'), $rawHash);
            $golden = engine_schema_descriptor_golden($engine);
            $bytes = \Tablo\RequiredFile::bytes(file_get_contents($root . '/database/' . $stem . '-layout.jsonl'));
            Assert::same(count(explode("\n", $bytes)), 20);
            Assert::same($loader->invoke(null, $root . '/database/' . $stem . '-layout.jsonl', $engine), $golden);
            Assert::same(\Tablo\ServerSchemaDescriptor::forEngine($engine), $golden);
            $loaded = engine_schema_descriptor_source($loader, $bytes, $engine);
            Assert::same($loaded['layout'], $golden); Assert::null($loaded['failure']);
            engine_schema_descriptor_golden_contract($golden);
            foreach ([9, 10] as $version) {
                $tables = array_values(array_filter($golden['tables'], static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
                $reference = hash('sha256', \Tablo\JsonEncoding::encode($tables));
                Assert::same($reference, engine_schema_descriptor_digest($engine, $version));
                Assert::same($checksum->invoke(null, $tables), $reference);
                Assert::same($sourceChecksum->invoke(null, $root . '/database/' . $stem . '-layout.jsonl', $engine, $version), $reference);
                engine_schema_source_checksum_contract($sourceChecksum, $bytes, $engine, $version, $reference);
            }
            Assert::same(engine_schema_descriptor_source($loader, str_replace("\n", replace: "\r\n", subject: $bytes), $engine)['layout'], $golden);
            engine_schema_descriptor_framing($loader, $bytes, $engine);
            engine_schema_descriptor_integrity($loader, $bytes, $engine);
            engine_schema_descriptor_io($loader, $bytes, $engine);
            engine_schema_admission_projection_contract($engine);
            engine_schema_admission_records_contract($bytes, $engine, $golden);
        }
        $missing = null;
        try { $loader->invoke(null, dirname(__DIR__) . '/fixtures/server-layouts/v10/absent-layout.jsonl', 'mysql'); }
        catch (\Tablo\DatabaseFailure $error) { $missing = $error; }
        engine_schema_descriptor_refusal($missing);
        engine_schema_descriptor_native_values();
        engine_schema_descriptor_manifest_contract();
    } finally { stream_wrapper_unregister('tabloschemafixture'); EngineSchemaDescriptorStream::$bytes = ''; }
}

/** @param \Tablo\ServerSchema::Layout $golden */
function engine_schema_descriptor_golden_contract(array $golden): void
{
    Assert::same(array_keys($golden), ['format', 'engine', 'logicalVersion', 'syntheticPredecessor', 'collation', 'tables']);
    Assert::same(count($golden['tables']), 18);
    foreach ($golden['tables'] as $table) {
        Assert::same(array_keys($table), ['name', 'columns', 'primary', 'checks', 'indexes', 'foreign', 'create', 'checksum']);
        Assert::same(hash('sha256', $table['create']), $table['checksum']);
    }
}

function engine_schema_descriptor_engine_selection(): void
{
    foreach ([null, 'unknown', 'sqlite'] as $engine) {
        $failure = null;
        try { \Tablo\ServerSchemaDescriptor::forEngine($engine); } catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
        Assert::same($failure?->category, 'configuration'); Assert::same($failure?->getPrevious(), null);
    }
}

/** The actual admission projection preserves all required data without changing a public full graph. */
function engine_schema_admission_projection_contract(string $engine): void
{
    $layout = \Tablo\ServerSchemaDescriptor::forEngine($engine);
    $golden = engine_schema_descriptor_golden($engine);
    Assert::same($layout, $golden);
    $before = \Tablo\JsonEncoding::encode($layout);
    $tables = \Tablo\ServerSchemaDescriptor::admissionTables($layout);
    Assert::same(\Tablo\ServerSchemaDescriptor::admissionTables(\Tablo\ServerSchemaDescriptor::forEngine($engine)), $tables);
    Assert::same(array_keys($tables), array_keys($layout['tables'])); Assert::same(count($tables), 18);
    $names = array_map(static fn (mixed $table): string => SqlColumn::string(JsonRecord::checked($table), 'name'), $tables);
    Assert::same($names, array_column($layout['tables'], 'name'));
    Assert::same(array_slice($names, offset: 0, length: 3), ['tablo_migration_journal', 'tablo_database_state', 'tablo_writer_guard']);
    Assert::true(in_array('read_api_token', $names, strict: true), 'metadata admission keeps the unfiltered source list');
    foreach ($layout['tables'] as $index => $table) {
        $actual = JsonRecord::checked($tables[$index] ?? null);
        $expected = $table; unset($expected['create'], $expected['checksum']);
        Assert::same(array_keys($actual), ['name', 'columns', 'primary', 'checks', 'indexes', 'foreign']);
        Assert::same($actual, $expected, 'every admission value, native type and order must be preserved');
        Assert::false(array_key_exists('create', $actual)); Assert::false(array_key_exists('checksum', $actual));
    }
    foreach ([9 => 17, 10 => 18] as $version => $count) {
        $selected = array_values(array_filter($names, static fn (string $name): bool => $version === 10 || $name !== 'read_api_token'));
        $full = array_values(array_filter($layout['tables'], static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
        Assert::same(count($selected), $count); Assert::same($selected, array_column($full, 'name'));
    }
    Assert::same($layout, $golden); Assert::same(\Tablo\JsonEncoding::encode($layout), $before);
    Assert::same(\Tablo\ServerSchemaDescriptor::forEngine($engine), $golden);
}

function engine_schema_descriptor_manifest_contract(): void
{
    $golden = engine_schema_descriptor_golden('mysql'); $tables = array_column($golden['tables'], column_key: null, index_key: 'name');
    $expected = [];
    foreach (\Tablo\DatabaseTransferManifest::TABLES as $name) {
        if (!array_key_exists($name, $tables)) { throw new \RuntimeException('Missing golden transfer table.'); }
        $table = $tables[$name]; $columns = [];
        foreach ($table['columns'] as $column) {
            if (array_key_exists('generated', $column)) { continue; }
            $columns[] = ['name' => $column['name'], 'type' => $column['type'], 'nullable' => $column['nullable']];
        }
        $expected[] = ['name' => $name, 'columns' => $columns, 'primary' => $table['primary']];
    }
    Assert::same(\Tablo\DatabaseTransferManifest::tables(), $expected);
    Assert::same(\Tablo\DatabaseTransferManifest::layout(), hash('sha256', \Tablo\JsonEncoding::encode($expected)));
    Assert::same(\Tablo\DatabaseTransferManifest::contract(), array_map(static fn (array $table): array => ['name' => $table['name'], 'columns' => $table['columns']], $expected));
}

/** @param array{failAt?: ?int, failClose?: bool, failOpen?: bool} $options
 * @return array{layout: ?array, failure: ?\Tablo\DatabaseFailure, read: int} */
function engine_schema_descriptor_source(\ReflectionMethod $loader, string $bytes, string $engine, array $options = []): array
{
    EngineSchemaDescriptorStream::$bytes = $bytes; EngineSchemaDescriptorStream::$position = 0;
    EngineSchemaDescriptorStream::$opens = 0; EngineSchemaDescriptorStream::$closes = 0;
    EngineSchemaDescriptorStream::$failAt = $options['failAt'] ?? null;
    EngineSchemaDescriptorStream::$failClose = $options['failClose'] ?? false;
    $failOpen = $options['failOpen'] ?? false; EngineSchemaDescriptorStream::$failOpen = $failOpen;
    $readers = array_keys(get_resources('stream'));
    $layout = null; $failure = null;
    try { $layout = JsonRecord::checked($loader->invoke(null, 'tabloschemafixture://descriptor-private-path-sentinel', $engine)); }
    catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
    Assert::same(EngineSchemaDescriptorStream::$opens, 1);
    Assert::same(EngineSchemaDescriptorStream::$closes, $failOpen ? 0 : 1);
    Assert::same(array_keys(get_resources('stream')), $readers, 'every opened source reader must be closed');
    if ($failure !== null) { engine_schema_descriptor_refusal($failure); }
    return ['layout' => $layout, 'failure' => $failure, 'read' => EngineSchemaDescriptorStream::$position];
}

function engine_schema_descriptor_refusal(?\Tablo\DatabaseFailure $failure): void
{
    if ($failure === null) { throw new \RuntimeException('Expected a source refusal.'); }
    Assert::same($failure->category, 'schema'); Assert::same($failure->getPrevious(), null);
    Assert::false(str_contains($failure->getMessage(), 'descriptor-private'));
    Assert::false(str_contains($failure->getMessage(), 'tabloschemafixture'));
}

function engine_schema_descriptor_framing(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    $lines = explode("\n", $bytes); Assert::same(array_pop($lines), '');
    if (!array_key_exists(0, $lines) || !array_key_exists(1, $lines) || !array_key_exists(2, $lines)) { throw new \RuntimeException('Incomplete framing fixture.'); }
    $header = $lines[0]; $first = $lines[1];
    foreach ([substr($bytes, offset: 0, length: -1), "\xEF\xBB\xBF" . $bytes, "\n" . $bytes, $bytes . "\n", $bytes . "\0",
        $header . "\n", implode("\n", array_slice($lines, offset: 1)) . "\n", substr($bytes, offset: 0, length: -80),
        str_replace('"records":2', replace: '"records":1', subject: $bytes), str_replace('"format":1', replace: '"format":"1"', subject: $bytes),
        str_replace("\n", replace: "\r", subject: $bytes)] as $broken) {
        $result = engine_schema_descriptor_source($loader, $broken, $engine);
        engine_schema_descriptor_refusal($result['failure']); Assert::null($result['layout']);
    }
    foreach (['{"tables":[{"unknown":"x"}]}', '{"tables":[{"name":"x","unknown":null}]}', '{"tables":[{"name":"x"},{}]}',
        '{"other":[{"name":"x"}]}', '{"tables":null}', '{"tables":[{"name":"\ud800"}]}', "{\"tables\":[{\"name\":\"\xFF\"}]}", '{'] as $record) {
        $changed = $lines; $changed[1] = $record;
        engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, implode("\n", $changed) . "\n", $engine)['failure']);
    }
    $table = \Tablo\server_schema_descriptor_table(JsonRecord::checked(json_decode($first, associative: true, flags: JSON_THROW_ON_ERROR)));
    $changed = $lines; $changed[1] = \Tablo\JsonEncoding::encode(['tables' => [array_reverse($table, preserve_keys: true)]]);
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, implode("\n", $changed) . "\n", $engine)['failure']);
    $missing = $table; unset($missing['checks']);
    $changed = $lines; $changed[1] = \Tablo\JsonEncoding::encode(['tables' => [$missing]]);
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, implode("\n", $changed) . "\n", $engine)['failure']);
    $changed = $lines; $changed[1] = \Tablo\JsonEncoding::encode(['tables' => [$table + ['unknown' => null]]]);
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, implode("\n", $changed) . "\n", $engine)['failure']);
    foreach ([str_replace('{"records":2,"layout":', replace: '{"unknown":0,"records":2,"layout":', subject: $bytes),
        str_replace('{"records":2,"layout":', replace: '{"layout":', subject: $bytes),
        str_replace('"format":1,"engine":', replace: '"engine":', subject: $bytes)] as $broken) {
        engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $broken, $engine)['failure']);
    }
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $bytes . $first . "\n", $engine)['failure']);
}

function engine_schema_descriptor_integrity(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    $lines = explode("\n", $bytes);
    foreach ([0, 8, 17] as $table) {
        $changed = $lines; $frame = JsonRecord::checked(json_decode($lines[1 + $table] ?? '', associative: true, flags: JSON_THROW_ON_ERROR));
        $value = \Tablo\server_schema_descriptor_table($frame); $value['checksum'] = str_repeat('0', times: 64);
        $changed[1 + $table] = \Tablo\JsonEncoding::encode(['tables' => [$value]]);
        $broken = implode("\n", $changed);
        $result = engine_schema_descriptor_source($loader, $broken, $engine);
        engine_schema_descriptor_refusal($result['failure']); Assert::null($result['layout']);
        Assert::same($result['read'], strlen($broken), 'assemble the whole graph before any CREATE validation, including the version9-excluded tail');
    }
    foreach ([str_replace('"format":1', replace: '"format":0', subject: $bytes),
        str_replace('"engine":"' . $engine . '"', replace: '"engine":"other"', subject: $bytes)] as $broken) {
        $result = engine_schema_descriptor_source($loader, $broken, $engine);
        engine_schema_descriptor_refusal($result['failure']); Assert::same($result['read'], strlen($broken));
    }
    $first = engine_schema_descriptor_source($loader, bytes: '{', engine: $engine)['failure'];
    $second = engine_schema_descriptor_source($loader, bytes: '{', engine: $engine)['failure'];
    engine_schema_descriptor_refusal($first); engine_schema_descriptor_refusal($second); Assert::false($first === $second);
    Assert::true(engine_schema_descriptor_source($loader, $bytes, $engine)['layout'] !== null, 'no cached refusal or graph');
}

function engine_schema_descriptor_io(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    foreach ([0, 9000, strlen($bytes)] as $position) {
        engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $bytes, $engine, options: ['failAt' => $position])['failure']);
    }
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $bytes, $engine, options: ['failOpen' => true])['failure']);
    engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $bytes, $engine, options: ['failClose' => true])['failure']);
    $primary = engine_schema_descriptor_source($loader, bytes: '{', engine: $engine)['failure'];
    $withCloseFailure = engine_schema_descriptor_source($loader, bytes: '{', engine: $engine, options: ['failClose' => true])['failure'];
    engine_schema_descriptor_refusal($primary); engine_schema_descriptor_refusal($withCloseFailure);
    Assert::same($withCloseFailure?->getLine(), $primary?->getLine(), 'close failure cannot replace the primary source refusal');
    set_error_handler(static function (int $severity, string $message): never { throw new \ErrorException($message, 0, $severity); });
    try {
        engine_schema_descriptor_refusal(engine_schema_descriptor_source($loader, $bytes, $engine, options: ['failAt' => 9000])['failure']);
        $restored = false;
        try { trigger_error('descriptor-private-handler-restoration-sentinel', E_USER_WARNING); } catch (\ErrorException) { $restored = true; }
        Assert::true($restored, 'native I/O must restore the caller error handler');
    }
    finally { restore_error_handler(); }
}

/** @return array */
function engine_schema_descriptor_record(\ReflectionFunction $decoder, string $bytes): array
{
    $stream = fopen('php://memory', mode: 'w+b');
    if ($stream === false) { throw new \RuntimeException('Cannot open pure record fixture.'); }
    try { fwrite($stream, $bytes); rewind($stream); return JsonRecord::checked($decoder->invoke($stream)); }
    finally { fclose($stream); }
}

/** Extract a decoded whole value without treating a legitimate null as a missing key. */
function engine_schema_descriptor_field_value(array $record, string $field): mixed
{
    $tables = JsonRecord::checked($record['tables'] ?? null);
    $table = JsonRecord::checked($tables[0] ?? null);
    if (!array_key_exists($field, $table)) { throw new \RuntimeException('Missing native field fixture.'); }
    return $table[$field];
}

function engine_schema_descriptor_native_values(): void
{
    $decoder = new \ReflectionFunction(\Tablo\server_schema_descriptor_record(...));
    $special = <<<'JSON'
"quote\" slash\/ backslash\\ control\u0000\r\n\t Caf\u00e9 \ud83e\uddea"
JSON;
    foreach (['null', 'false', 'true', '0', '1', '-1', '1.0', '1.5', '-0.0', '1e2', '"1"', '""', '[]', '{}',
        '{"3":"three","1":"one"}', '[null,false,true,0,"1",[],{"nested":[]}]', $special] as $value) {
        $line = engine_schema_descriptor_table_record('columns', $value);
        $decoded = engine_schema_descriptor_record($decoder, $line . "\n");
        $reference = JsonRecord::checked(json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR));
        Assert::same($decoded, $reference);
        Assert::same(\Tablo\server_schema_descriptor_table($decoded), JsonRecord::checked(JsonRecord::checked($reference['tables'] ?? null)[0] ?? null));
        Assert::same(engine_schema_descriptor_field_value($decoded, field: 'columns'), engine_schema_descriptor_field_value($reference, field: 'columns'));
    }
    $boundary = false;
    foreach (['columns', 'primary', 'checks', 'indexes', 'foreign'] as $field) {
        foreach ([15, 16, 17, 507, 508, 509, 510, 511, 512, 513] as $depth) {
            $value = str_repeat('[', $depth) . '0' . str_repeat(']', $depth);
            $line = engine_schema_descriptor_table_record($field, $value);
            $reference = engine_schema_checksum_outcome(static fn () => engine_schema_descriptor_field_value(JsonRecord::checked(json_decode('{"format":1,' . substr($line, offset: 1), associative: true, flags: JSON_THROW_ON_ERROR)), $field));
            $actual = engine_schema_checksum_outcome(static fn () => \Tablo\server_schema_descriptor_table(engine_schema_descriptor_record($decoder, $line . "\n"))[$field] ?? null);
            Assert::same($actual, $reference);
            $unwrapped = engine_schema_checksum_outcome(static fn () => json_decode('{"' . $field . '":' . $value . '}', associative: true, flags: JSON_THROW_ON_ERROR));
            if ($reference['error'] === JSON_ERROR_DEPTH && $unwrapped['error'] === null) { $boundary = true; }
        }
    }
    Assert::true($boundary, 'unwrapped native JSON must expose a depth-boundary counterexample');
    $line = engine_schema_descriptor_table_record('name', value: '"limit"');
    $limit = $line . str_repeat(' ', times: engine_schema_descriptor_padding(131_071, $line)) . "\n";
    Assert::same(strlen($limit), 131_072);
    Assert::same(engine_schema_descriptor_record($decoder, $limit), JsonRecord::checked(json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR)));
    $failure = null;
    try { engine_schema_descriptor_record($decoder, ' ' . $limit); } catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
    engine_schema_descriptor_refusal($failure);
    engine_schema_descriptor_chunk_boundaries($decoder);
}

/** Complete table framing retains every field's native JSON spelling and depth. */
function engine_schema_descriptor_table_record(string $field, string $value): string
{
    $fields = ['name' => '"native"', 'columns' => '[]', 'primary' => '[]', 'checks' => '[]', 'indexes' => '[]', 'foreign' => '[]', 'create' => '""', 'checksum' => '""'];
    if (!array_key_exists($field, $fields)) { throw new \RuntimeException('Unknown native table fixture field.'); }
    $fields[$field] = $value; $members = [];
    foreach ($fields as $name => $native) { $members[] = '"' . $name . '":' . $native; }
    return '{"tables":[{' . implode(',', $members) . '}]}';
}

/** @return int<0, max> */
function engine_schema_descriptor_padding(int $length, string $prefix): int
{
    $padding = $length - strlen($prefix);
    if ($padding < 0) { throw new \RuntimeException('Native table fixture exceeds its boundary.'); }
    return $padding;
}

/** Frame terminators and UTF-8 may cross the 4,095-byte fgets payload boundary. */
function engine_schema_descriptor_chunk_boundaries(\ReflectionFunction $decoder): void
{
    $line = engine_schema_descriptor_table_record('name', value: '"boundary"');
    $expected = JsonRecord::checked(json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR));
    foreach ([4093, 4094, 4095, 4096, 8188, 8189, 8190, 8191] as $bodyBytes) {
        $body = $line . str_repeat(' ', times: engine_schema_descriptor_padding($bodyBytes, $line));
        Assert::same(strlen($body), $bodyBytes);
        foreach (["\n", "\r\n"] as $terminator) {
            Assert::same(strlen($body . $terminator), $bodyBytes + strlen($terminator));
            Assert::same(engine_schema_descriptor_record($decoder, $body . $terminator), $expected);
        }
    }
    $prefix = '{"tables":[{"name":"';
    $utf8 = engine_schema_descriptor_table_record('name', '"' . str_repeat('x', times: engine_schema_descriptor_padding(4094, $prefix)) . 'é"') . "\n";
    Assert::same(strpos($utf8, needle: 'é'), 4094);
    Assert::same(engine_schema_descriptor_record($decoder, $utf8), JsonRecord::checked(json_decode($utf8, associative: true, flags: JSON_THROW_ON_ERROR)));
    $body = $line . str_repeat(' ', times: engine_schema_descriptor_padding(4094, $line));
    Assert::same(strlen($body), 4094);
    $failure = null;
    try { engine_schema_descriptor_record($decoder, $body . "\r"); } catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
    engine_schema_descriptor_refusal($failure);
    $stream = fopen('php://memory', mode: 'w+b');
    if ($stream === false) { throw new \RuntimeException('Cannot open paired record fixture.'); }
    try {
        fwrite($stream, $body . "\r\n" . $line . "\n"); rewind($stream);
        Assert::same(JsonRecord::checked($decoder->invoke($stream)), $expected);
        Assert::same(JsonRecord::checked($decoder->invoke($stream)), $expected);
        Assert::same(fgetc($stream), false);
    } finally { fclose($stream); }
}

/** @param list<array> $tables */
function engine_schema_checksum_equivalent(\ReflectionMethod $method, array $tables): void
{
    $before = $tables;
    $reference = hash('sha256', \Tablo\JsonEncoding::encode($tables));
    Assert::same($method->invoke(null, $tables), $reference);
    Assert::same($tables, $before);
}

function engine_schema_checksum_chunks(\ReflectionMethod $method): void
{
    foreach ([0, 1, 4093, 4094, 4095, 4096, 4097, 8191, 8192, 8193] as $length) {
        $payload = str_repeat('x', $length) . "\"\\/\0\r\n\tCafé";
        engine_schema_checksum_equivalent($method, [['payload' => $payload], ['payload' => 'tail'], ['payload' => $payload]]);
    }
}

function engine_schema_checksum_depth(\ReflectionMethod $method): void
{
    $nested = 'leaf'; $boundary = false;
    for ($depth = 1; $depth <= 513; $depth++) {
        $nested = [$nested];
        if ($depth < 508) { continue; }
        $tables = [['payload' => $nested]];
        $reference = engine_schema_checksum_outcome(static fn () => hash('sha256', \Tablo\JsonEncoding::encode($tables)));
        Assert::same(engine_schema_checksum_outcome(static fn () => $method->invoke(null, $tables)), $reference);
        $direct = engine_schema_checksum_outcome(static fn () => \Tablo\JsonEncoding::encode($tables[0]));
        if ($reference['error'] === JSON_ERROR_DEPTH && $direct['error'] === null) { $boundary = true; }
    }
    Assert::true($boundary);
}

function engine_schema_checksum_encoding_failures(\ReflectionMethod $method): void
{
    foreach ([[['payload' => "\xFF"]], [['payload' => 'valid'], ['payload' => "\xC3\x28"]], [['value' => NAN]], [['value' => INF]]] as $tables) {
        $reference = engine_schema_checksum_outcome(static fn () => hash('sha256', \Tablo\JsonEncoding::encode($tables)));
        Assert::true($reference['error'] !== null);
        Assert::same(engine_schema_checksum_outcome(static fn () => $method->invoke(null, $tables)), $reference);
    }
}

/** @param \Closure(): mixed $operation
 * @return array{value: mixed, error: int|string|null, message: ?string} */
function engine_schema_checksum_outcome(\Closure $operation): array
{
    try { return ['value' => $operation(), 'error' => null, 'message' => null]; }
    catch (\JsonException $failure) { return ['value' => null, 'error' => $failure->getCode(), 'message' => $failure->getMessage()]; }
}

function engine_migration_uncertain_phase(): void
{
    $fixture = new EngineFixture('migration-phase-uncertain');
    try {
        $db = $fixture->maintenance(); $tables = EngineMigrationPreviousTest::materialize($fixture, $db); $before = EngineMigrationPreviousTest::snapshot($db, $tables);
        $journal = ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal');
        $fault = new EngineReadApiCommitPdo($fixture, 'migrator'); $fault->loseAcknowledgement = true; $fault->failRollback = true;
        $caught = null;
        try { (new ServerMigration($fault, $fixture->vault))->migrate(); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'uncertain'); Assert::same($caught?->getMessage(), 'Storage outcome uncertain.');
        Assert::same($caught?->getPrevious(), null); Assert::same($caught->sqlState, null); Assert::same($caught->nativeCode, null);
        Assert::true($fault->committedWithoutAcknowledgement); Assert::same($fault->rollbackAttempts, 1);
        Assert::false($fault->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($fault));
        Assert::same((new ServerMigration($db, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 9, 'phase' => 'migrating']);
        Assert::same(count(ServerSchema::objects($db)), 17); Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'), $journal);
        Assert::same(EngineMigrationPreviousTest::snapshot($db, $tables), $before);
        $resumed = new ServerMigration($fixture->maintenance(), $fixture->vault); $resumed->migrate();
        Assert::same($resumed->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same(ServerSchema::rows($db, 'SELECT predecessor,target,phase FROM tablo_migration_journal'), [['predecessor' => 9, 'target' => 10, 'phase' => 'done']]);
        Assert::same(EngineMigrationPreviousTest::snapshot($db, $tables), $before);
    } finally { $fixture->close(); }
}

function engine_migration_uncertain_publication(): void
{
    $fixture = new EngineFixture('migration-publish-uncertain');
    try {
        $db = $fixture->maintenance(); $tables = EngineMigrationPreviousTest::materialize($fixture, $db); $before = EngineMigrationPreviousTest::snapshot($db, $tables);
        $fault = new EngineReadApiCommitPdo($fixture, 'migrator'); $points = [];
        $migration = new ServerMigration($fault, $fixture->vault, static function (string $phase, int $step) use ($fault, &$points): void {
            $points[] = [$phase, $step];
            if ($phase === 'before-publication') { $fault->loseAcknowledgement = true; $fault->failRollback = true; }
        });
        $caught = null;
        try { $migration->migrate(); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'uncertain'); Assert::same($caught?->getMessage(), 'Storage outcome uncertain.');
        Assert::same($caught?->getPrevious(), null); Assert::same($caught->sqlState, null); Assert::same($caught->nativeCode, null);
        Assert::true($fault->committedWithoutAcknowledgement); Assert::same($fault->rollbackAttempts, 1);
        Assert::false($fault->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($fault));
        Assert::same(array_pop($points), ['before-publication', 10]);
        $state = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'); $journal = ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal');
        Assert::same($migration->status(), ['format' => 1, 'logical_version' => 10, 'phase' => 'ready']);
        Assert::same(count(ServerSchema::objects($db)), 18);
        Assert::same(ServerSchema::rows($db, 'SELECT predecessor,target,phase FROM tablo_migration_journal'), [['predecessor' => 9, 'target' => 10, 'phase' => 'done']]);
        (new ServerMigration($fixture->maintenance(), $fixture->vault))->migrate();
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $state);
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_migration_journal'), $journal);
        Assert::same(EngineMigrationPreviousTest::snapshot($db, $tables), $before);
    } finally { $fixture->close(); }
}

function engine_migration_phase_constraint(): void
{
    $fixture = new EngineFixture('migration-phase-constraint');
    try {
        $db = $fixture->maintenance(); $tables = EngineMigrationPreviousTest::materialize($fixture, $db); $before = EngineMigrationPreviousTest::snapshot($db, $tables);
        $state = ServerSchema::rows($db, 'SELECT * FROM tablo_database_state');
        $fault = new EngineReadApiCommitPdo($fixture, 'migrator'); $fault->loseAcknowledgement = true; $caught = null;
        try {
            \Tablo\ServerMigrationLock::run($fault, $fixture->vault, static function (\Tablo\ServerLock $lock) use ($fault): void {
                unset($lock); (new \Tablo\ServerMigrationState($fault))->phase('outside-schema');
            });
        } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'constraint'); Assert::same($caught?->getPrevious(), null);
        Assert::same($caught?->getMessage(), 'Database constraint refused.'); Assert::true(in_array($caught->nativeCode, [3819, 4025], strict: true));
        Assert::false($fault->committedWithoutAcknowledgement); Assert::same($fault->rollbackAttempts, 1);
        Assert::false($fault->inTransaction()); Assert::false(\Tablo\WriteTransaction::owns($fault));
        Assert::same(ServerSchema::rows($db, 'SELECT * FROM tablo_database_state'), $state);
        Assert::same(EngineMigrationPreviousTest::snapshot($db, $tables), $before);
    } finally { $fixture->close(); }
}

/** Literal independent predecessor shape for these tests; SQL bytes are never inferred from current bootstrap. */
function engine_synthetic_logical9_fixture(string $engine): array
{
    $json = match ($engine) {
        'mysql' => <<<'MYSQL_LOGICAL9_D0'
{
  "schema": "tablo/synthetic-logical9-fixture/v1",
  "engine": "mysql",
  "metadataFormat": 1,
  "logicalVersion": 9,
  "domainTables": 14,
  "provenance": "Frozen reviewed D0 synthetic physical9 contract, not a historical deployment or an independent SQL semantic oracle; literal CREATE statements, not current bootstrap followed by dropping a table.",
  "layoutChecksum": "4aea292a93b941e03fde9425fe9171d5aed0f640749d6db5264be27737c5402a",
  "tables": [
    {
      "name": "tablo_migration_journal",
      "primary": [
        "plan",
        "step"
      ],
      "sql": "CREATE TABLE `tablo_migration_journal` (\n  `plan` varbinary(64) NOT NULL,\n  `step` bigint NOT NULL,\n  `checksum` varbinary(64) NOT NULL,\n  `predecessor` bigint NOT NULL,\n  `target` bigint NOT NULL,\n  `before_predicate` varbinary(96) NOT NULL,\n  `after_predicate` varbinary(96) NOT NULL,\n  `phase` varbinary(16) NOT NULL,\n  `cursor` longblob NULL,\n  `failure_category` varbinary(32) NULL,\n  PRIMARY KEY (`plan`,`step`),\n  CONSTRAINT `tablo_migration_journal_c1` CHECK (`step` >= 0),\n  CONSTRAINT `tablo_migration_journal_c2` CHECK (`phase` IN ('intent','done','failed'))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "ef7d4413b1b7292b48bbc689ea8bd7aa216a08a8ff75b963276245df3d3ea3cc"
    },
    {
      "name": "tablo_database_state",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `tablo_database_state` (\n  `id` bigint NOT NULL,\n  `format` bigint NOT NULL,\n  `logical_version` bigint NOT NULL,\n  `layout_checksum` varbinary(64) NOT NULL,\n  `phase` varbinary(16) NOT NULL,\n  `installation_id` varbinary(32) NOT NULL,\n  `key_verifier` varbinary(64) NOT NULL,\n  `committed_history_high_watermark` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `tablo_database_state_c1` CHECK (`id` = 1),\n  CONSTRAINT `tablo_database_state_c2` CHECK (`format` = 1),\n  CONSTRAINT `tablo_database_state_c3` CHECK (`logical_version` BETWEEN 0 AND 10),\n  CONSTRAINT `tablo_database_state_c4` CHECK (`phase` IN ('initializing','migrating','importing','failed','ready')),\n  CONSTRAINT `tablo_database_state_c5` CHECK (`committed_history_high_watermark` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "208a5dd8bc7c216bc21d1197fc2684f70b6cd78ed2293681bd9c1b676000f81b"
    },
    {
      "name": "tablo_writer_guard",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `tablo_writer_guard` (\n  `id` bigint NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `tablo_writer_guard_c1` CHECK (`id` = 1)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "18e9ce5a844009b04f82713eac4f235c19cabe900724d7208dfe871c463cecba"
    },
    {
      "name": "users",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `users` (\n  `id` bigint NOT NULL,\n  `password_hash` longblob NOT NULL,\n  `created_at` varbinary(20) NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `users_c1` CHECK (`id` = 1),\n  CONSTRAINT `users_c2` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "43c84382eb87afc35c2a1a1885de833902c887bb71bc0f894cd08be94616a396"
    },
    {
      "name": "git_tokens",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `git_tokens` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `name` varchar(80) NOT NULL,\n  `provider` varbinary(32) NOT NULL,\n  `encrypted_token` longblob NOT NULL,\n  `created_at` varbinary(20) NOT NULL,\n  `updated_at` varbinary(20) NOT NULL,\n  `name_key` varbinary(320) GENERATED ALWAYS AS (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CAST(`name` AS BINARY),X'41',X'61'),X'42',X'62'),X'43',X'63'),X'44',X'64'),X'45',X'65'),X'46',X'66'),X'47',X'67'),X'48',X'68'),X'49',X'69'),X'4a',X'6a'),X'4b',X'6b'),X'4c',X'6c'),X'4d',X'6d'),X'4e',X'6e'),X'4f',X'6f'),X'50',X'70'),X'51',X'71'),X'52',X'72'),X'53',X'73'),X'54',X'74'),X'55',X'75'),X'56',X'76'),X'57',X'77'),X'58',X'78'),X'59',X'79'),X'5a',X'7a')) STORED,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `git_tokens_provider_name` (`provider`,`name_key`),\n  CONSTRAINT `git_tokens_c1` CHECK (OCTET_LENGTH(`name`) BETWEEN 1 AND 320 AND LOCATE(X'00',CAST(`name` AS BINARY)) = 0),\n  CONSTRAINT `git_tokens_c2` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `git_tokens_c3` CHECK (OCTET_LENGTH(`updated_at`) = 20 AND ASCII(SUBSTRING(`updated_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`updated_at`,5,1) = '-' AND SUBSTRING(`updated_at`,8,1) = '-' AND SUBSTRING(`updated_at`,11,1) = 'T' AND SUBSTRING(`updated_at`,14,1) = ':' AND SUBSTRING(`updated_at`,17,1) = ':' AND SUBSTRING(`updated_at`,20,1) = 'Z' AND CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`updated_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`updated_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`updated_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "455316fe7121de7abffc7a8e618751e4f40f6709e81715ebdf3b54485042b8cb"
    },
    {
      "name": "sites",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `sites` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `name` longtext NOT NULL,\n  `url` longtext NOT NULL,\n  `provider` varbinary(16) NOT NULL DEFAULT 'github',\n  `repository` longtext NOT NULL,\n  `github_token` longblob NULL,\n  `git_token_id` bigint NULL,\n  `branch` longtext NOT NULL DEFAULT ('main'),\n  `health_path` longtext NOT NULL DEFAULT ('/up'),\n  `version_path` longtext NOT NULL DEFAULT (''),\n  `version_json_path` longtext NOT NULL DEFAULT (''),\n  `health_check_mode` varbinary(16) NOT NULL DEFAULT 'http',\n  `health_json_path` longtext NOT NULL DEFAULT (''),\n  `health_json_operator` varbinary(16) NOT NULL DEFAULT '==',\n  `health_json_expected_value` longtext NOT NULL DEFAULT (''),\n  `comparison_mode` varbinary(16) NOT NULL DEFAULT 'release',\n  `enabled` bigint NOT NULL DEFAULT 1,\n  `sort_order` bigint NOT NULL DEFAULT 0,\n  `online` bigint NULL,\n  `deployed_version` longtext NULL,\n  `deployed_commit` varbinary(256) NULL,\n  `latest_release` longtext NULL,\n  `latest_commit` varbinary(256) NULL,\n  `open_issues` bigint NULL,\n  `open_prs` bigint NULL,\n  `response_time_ms` bigint NULL,\n  `last_error` longtext NULL,\n  `checked_at` varbinary(20) NULL,\n  `created_at` varbinary(20) NOT NULL,\n  `updated_at` varbinary(20) NOT NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `config_revision` bigint NOT NULL DEFAULT 0,\n  `endpoint_token` longblob NULL,\n  PRIMARY KEY (`id`),\n  KEY `sites_git_token` (`git_token_id`),\n  CONSTRAINT `sites_git_token_id_fk` FOREIGN KEY (`git_token_id`) REFERENCES `git_tokens` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,\n  CONSTRAINT `sites_c1` CHECK (`provider` IN ('github')),\n  CONSTRAINT `sites_c2` CHECK (`health_check_mode` IN ('http','json')),\n  CONSTRAINT `sites_c3` CHECK (`health_json_operator` IN ('>','>=','<','<=','!=','==','contains')),\n  CONSTRAINT `sites_c4` CHECK (`comparison_mode` IN ('release','branch')),\n  CONSTRAINT `sites_c5` CHECK (`enabled` IN (0,1)),\n  CONSTRAINT `sites_c6` CHECK (`online` IN (0,1)),\n  CONSTRAINT `sites_c7` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `sites_c8` CHECK (`config_revision` >= 0),\n  CONSTRAINT `sites_c9` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `sites_c10` CHECK (OCTET_LENGTH(`updated_at`) = 20 AND ASCII(SUBSTRING(`updated_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`updated_at`,5,1) = '-' AND SUBSTRING(`updated_at`,8,1) = '-' AND SUBSTRING(`updated_at`,11,1) = 'T' AND SUBSTRING(`updated_at`,14,1) = ':' AND SUBSTRING(`updated_at`,17,1) = ':' AND SUBSTRING(`updated_at`,20,1) = 'Z' AND CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`updated_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`updated_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`updated_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `sites_c11` CHECK (OCTET_LENGTH(`checked_at`) = 20 AND ASCII(SUBSTRING(`checked_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`checked_at`,5,1) = '-' AND SUBSTRING(`checked_at`,8,1) = '-' AND SUBSTRING(`checked_at`,11,1) = 'T' AND SUBSTRING(`checked_at`,14,1) = ':' AND SUBSTRING(`checked_at`,17,1) = ':' AND SUBSTRING(`checked_at`,20,1) = 'Z' AND CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`checked_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`checked_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`checked_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "76247cc13d9a7bcb63b05ebb1beefbb223331559d4009681393df6ec342bdd28"
    },
    {
      "name": "login_limits",
      "primary": [
        "key"
      ],
      "sql": "CREATE TABLE `login_limits` (\n  `key` varbinary(128) NOT NULL,\n  `failures` bigint NOT NULL DEFAULT 0,\n  `window_start` bigint NOT NULL,\n  PRIMARY KEY (`key`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "fd866c7548a6830e9145bd84e6eeb00c23e96a337151812439fa0d6af71f7477"
    },
    {
      "name": "github_cooldowns",
      "primary": [
        "scope",
        "resource"
      ],
      "sql": "CREATE TABLE `github_cooldowns` (\n  `scope` varbinary(256) NOT NULL,\n  `resource` varbinary(16) NOT NULL,\n  `eligible_at` bigint NOT NULL,\n  PRIMARY KEY (`scope`,`resource`),\n  CONSTRAINT `github_cooldowns_c1` CHECK (`resource` IN ('core','search','secondary')),\n  CONSTRAINT `github_cooldowns_c2` CHECK (`eligible_at` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "235bcf25475be39e421e69897a13e9480eb3ded35096388922eae2213382a7c6"
    },
    {
      "name": "installation_settings",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `installation_settings` (\n  `id` bigint NOT NULL,\n  `check_interval_minutes` bigint NOT NULL DEFAULT 10,\n  `session_idle_timeout_minutes` bigint NOT NULL DEFAULT 30,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `installation_settings_c1` CHECK (`id` = 1),\n  CONSTRAINT `installation_settings_c2` CHECK (`check_interval_minutes` BETWEEN 1 AND 153722867280912930),\n  CONSTRAINT `installation_settings_c3` CHECK (`session_idle_timeout_minutes` BETWEEN 1 AND 720)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "616cb9ee35630c4c3c076dee5d7de6dcc6b1db00d612684e38f763e4ef3ba382"
    },
    {
      "name": "worker_runtime",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `worker_runtime` (\n  `id` bigint NOT NULL,\n  `run_id` longblob NULL,\n  `stop_requested` bigint NOT NULL DEFAULT 0,\n  `fairness_turn` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `worker_runtime_c1` CHECK (`id` = 1),\n  CONSTRAINT `worker_runtime_c2` CHECK (`stop_requested` IN (0,1)),\n  CONSTRAINT `worker_runtime_c3` CHECK (`fairness_turn` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "7a87fb72f6c77d50788cd03242e8d40304102042c9c2d185eea8d6db19a0cd65"
    },
    {
      "name": "worker_progress",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `worker_progress` (\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `latest_release` bigint NOT NULL DEFAULT 0,\n  `latest_commit` bigint NOT NULL DEFAULT 0,\n  `open_issues` bigint NOT NULL DEFAULT 0,\n  `open_prs` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `worker_progress_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `worker_progress_c1` CHECK (`config_revision` >= 0),\n  CONSTRAINT `worker_progress_c2` CHECK (`latest_release` >= 0),\n  CONSTRAINT `worker_progress_c3` CHECK (`latest_commit` >= 0),\n  CONSTRAINT `worker_progress_c4` CHECK (`open_issues` >= 0),\n  CONSTRAINT `worker_progress_c5` CHECK (`open_prs` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "04ddadae7e1cdc13bb0583595dda67e2d07bf05a8b5ec76829442e7aa5159ceb"
    },
    {
      "name": "check_history",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `check_history` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `checked_at` varbinary(20) NOT NULL,\n  `online` bigint NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `response_time_ms` bigint NULL,\n  `deployed_version` varbinary(200) NULL,\n  `deployed_commit` varbinary(64) NULL,\n  `latest_release` varbinary(200) NULL,\n  `latest_commit` varbinary(64) NULL,\n  `version_status` varbinary(16) NOT NULL,\n  `version_error_code` varbinary(64) NULL,\n  `version_http_status` bigint NULL,\n  `release_error_code` varbinary(64) NULL,\n  `release_http_status` bigint NULL,\n  `commit_error_code` varbinary(64) NULL,\n  `commit_http_status` bigint NULL,\n  PRIMARY KEY (`id`),\n  KEY `check_history_site_time` (`site_id`,`checked_at`,`id`),\n  KEY `check_history_time` (`checked_at`,`id`),\n  CONSTRAINT `check_history_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `check_history_c1` CHECK (`config_revision` >= 0),\n  CONSTRAINT `check_history_c2` CHECK (OCTET_LENGTH(`checked_at`) = 20 AND ASCII(SUBSTRING(`checked_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`checked_at`,5,1) = '-' AND SUBSTRING(`checked_at`,8,1) = '-' AND SUBSTRING(`checked_at`,11,1) = 'T' AND SUBSTRING(`checked_at`,14,1) = ':' AND SUBSTRING(`checked_at`,17,1) = ':' AND SUBSTRING(`checked_at`,20,1) = 'Z' AND CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`checked_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`checked_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`checked_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `check_history_c3` CHECK (`online` IN (0,1)),\n  CONSTRAINT `check_history_c4` CHECK (`health_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition','redirect-location-missing','redirect-location-invalid','redirect-origin','redirect-cycle','redirect-limit')),\n  CONSTRAINT `check_history_c5` CHECK (`response_time_ms` BETWEEN 0 AND 2147483647),\n  CONSTRAINT `check_history_c6` CHECK (`version_status` IN ('skipped','ok','error')),\n  CONSTRAINT `check_history_c7` CHECK (`version_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition','invalid-data','redirect-location-missing','redirect-location-invalid','redirect-origin','redirect-cycle','redirect-limit')),\n  CONSTRAINT `check_history_c8` CHECK (`release_error_code` IN ('access','rate-limit','budget','credential-changed','renamed','branch-unconfirmed','release-unconfirmed','incomplete-search','invalid-data','unavailable','check-error')),\n  CONSTRAINT `check_history_c9` CHECK (`commit_error_code` IN ('access','rate-limit','budget','credential-changed','renamed','branch-unconfirmed','release-unconfirmed','incomplete-search','invalid-data','unavailable','check-error')),\n  CONSTRAINT `check_history_c10` CHECK ((`version_status` = 'error') = (`version_error_code` IS NOT NULL)),\n  CONSTRAINT `check_history_c11` CHECK (OCTET_LENGTH(`deployed_commit`) BETWEEN 7 AND 64 AND OCTET_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`deployed_commit`,'0',''),'1',''),'2',''),'3',''),'4',''),'5',''),'6',''),'7',''),'8',''),'9',''),'a',''),'b',''),'c',''),'d',''),'e',''),'f','')) = 0),\n  CONSTRAINT `check_history_c12` CHECK (OCTET_LENGTH(`latest_commit`) BETWEEN 7 AND 64 AND OCTET_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`latest_commit`,'0',''),'1',''),'2',''),'3',''),'4',''),'5',''),'6',''),'7',''),'8',''),'9',''),'a',''),'b',''),'c',''),'d',''),'e',''),'f','')) = 0),\n  CONSTRAINT `check_history_c13` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c14` CHECK (`version_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c15` CHECK (`release_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c16` CHECK (`commit_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c17` CHECK (OCTET_LENGTH(`deployed_version`) BETWEEN 1 AND 200),\n  CONSTRAINT `check_history_c18` CHECK (OCTET_LENGTH(`latest_release`) BETWEEN 1 AND 200)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "4d1cfce1f771993fe5a053ea526e86f868cebba7362ca7481df3198b17878b8a"
    },
    {
      "name": "incidents",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `incidents` (\n  `id` bigint NOT NULL,\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `opened_at` varbinary(20) NOT NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `recovery_history_id` bigint NULL,\n  `recovered_at` varbinary(20) NULL,\n  `interruption_history_id` bigint NULL,\n  `end_reason` varbinary(32) NULL,\n  `uncertain` bigint NOT NULL DEFAULT 0,\n  `clock_invalid` bigint NOT NULL DEFAULT 0,\n  `unresolved_site_id` bigint GENERATED ALWAYS AS (CASE WHEN `end_reason` IS NULL THEN `site_id` ELSE NULL END) VIRTUAL,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `incidents_unresolved` (`unresolved_site_id`),\n  KEY `incidents_site_id` (`site_id`,`id`),\n  CONSTRAINT `incidents_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `incidents_c1` CHECK (`id` > 0),\n  CONSTRAINT `incidents_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `incidents_c3` CHECK (OCTET_LENGTH(`opened_at`) = 20 AND ASCII(SUBSTRING(`opened_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`opened_at`,5,1) = '-' AND SUBSTRING(`opened_at`,8,1) = '-' AND SUBSTRING(`opened_at`,11,1) = 'T' AND SUBSTRING(`opened_at`,14,1) = ':' AND SUBSTRING(`opened_at`,17,1) = ':' AND SUBSTRING(`opened_at`,20,1) = 'Z' AND CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`opened_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`opened_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`opened_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `incidents_c4` CHECK (OCTET_LENGTH(`recovered_at`) = 20 AND ASCII(SUBSTRING(`recovered_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`recovered_at`,5,1) = '-' AND SUBSTRING(`recovered_at`,8,1) = '-' AND SUBSTRING(`recovered_at`,11,1) = 'T' AND SUBSTRING(`recovered_at`,14,1) = ':' AND SUBSTRING(`recovered_at`,17,1) = ':' AND SUBSTRING(`recovered_at`,20,1) = 'Z' AND CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`recovered_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`recovered_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`recovered_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `incidents_c5` CHECK (`health_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition')),\n  CONSTRAINT `incidents_c6` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `incidents_c7` CHECK (`recovery_history_id` > `id`),\n  CONSTRAINT `incidents_c8` CHECK (`interruption_history_id` > `id`),\n  CONSTRAINT `incidents_c9` CHECK (`end_reason` IN ('recovered','config-changed')),\n  CONSTRAINT `incidents_c10` CHECK (`uncertain` IN (0,1)),\n  CONSTRAINT `incidents_c11` CHECK (`clock_invalid` IN (0,1)),\n  CONSTRAINT `incidents_c12` CHECK ((`end_reason` <=> 'recovered') = (`recovery_history_id` IS NOT NULL AND `recovered_at` IS NOT NULL)),\n  CONSTRAINT `incidents_c13` CHECK ((`end_reason` <=> 'config-changed') = (`interruption_history_id` IS NOT NULL)),\n  CONSTRAINT `incidents_c14` CHECK ((`end_reason` <=> 'recovered') OR (`recovery_history_id` IS NULL AND `recovered_at` IS NULL))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "a508085570003082f95388bede5a1e4600c084bc21476629fcc49a5d070a395c"
    },
    {
      "name": "incident_checkpoints",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `incident_checkpoints` (\n  `site_id` bigint NOT NULL,\n  `last_history_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `watermark` varbinary(20) NOT NULL,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `incident_checkpoints_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `incident_checkpoints_c1` CHECK (`last_history_id` > 0),\n  CONSTRAINT `incident_checkpoints_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `incident_checkpoints_c3` CHECK (OCTET_LENGTH(`watermark`) = 20 AND ASCII(SUBSTRING(`watermark`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`watermark`,5,1) = '-' AND SUBSTRING(`watermark`,8,1) = '-' AND SUBSTRING(`watermark`,11,1) = 'T' AND SUBSTRING(`watermark`,14,1) = ':' AND SUBSTRING(`watermark`,17,1) = ':' AND SUBSTRING(`watermark`,20,1) = 'Z' AND CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`watermark`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`watermark`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`watermark`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "e822359ca0e07448ff37d54ae691a9661d5baff5d79aa2f48cf5d4f731693a84"
    },
    {
      "name": "notification_settings",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `notification_settings` (\n  `id` bigint NOT NULL,\n  `installation_id` varbinary(32) NOT NULL,\n  `revision` bigint NOT NULL DEFAULT 0,\n  `activation_id` bigint NOT NULL DEFAULT 0,\n  `enabled` bigint NOT NULL DEFAULT 0,\n  `blocked` bigint NOT NULL DEFAULT 0,\n  `endpoint_cipher` longblob NULL,\n  `bearer_cipher` longblob NULL,\n  `unavailable` bigint NOT NULL DEFAULT 1,\n  `recovery` bigint NOT NULL DEFAULT 1,\n  `version_lag` bigint NOT NULL DEFAULT 1,\n  `include_name` bigint NOT NULL DEFAULT 0,\n  `include_url` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `notification_settings_c1` CHECK (`id` = 1),\n  CONSTRAINT `notification_settings_c2` CHECK (OCTET_LENGTH(`installation_id`) = 32),\n  CONSTRAINT `notification_settings_c3` CHECK (`revision` >= 0),\n  CONSTRAINT `notification_settings_c4` CHECK (`activation_id` >= 0),\n  CONSTRAINT `notification_settings_c5` CHECK (`enabled` IN (0,1)),\n  CONSTRAINT `notification_settings_c6` CHECK (`blocked` IN (0,1)),\n  CONSTRAINT `notification_settings_c7` CHECK (`unavailable` IN (0,1)),\n  CONSTRAINT `notification_settings_c8` CHECK (`recovery` IN (0,1)),\n  CONSTRAINT `notification_settings_c9` CHECK (`version_lag` IN (0,1)),\n  CONSTRAINT `notification_settings_c10` CHECK (`include_name` IN (0,1)),\n  CONSTRAINT `notification_settings_c11` CHECK (`include_url` IN (0,1))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "e1c8116920f65829bc4c7feb3b356d02facec0e22f19f91f2a14ebb8c2f19882"
    },
    {
      "name": "notification_checkpoints",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `notification_checkpoints` (\n  `site_id` bigint NOT NULL,\n  `last_history_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `state` longtext NOT NULL,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `notification_checkpoints_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `notification_checkpoints_c1` CHECK (`last_history_id` > 0),\n  CONSTRAINT `notification_checkpoints_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `notification_checkpoints_c3` CHECK (OCTET_LENGTH(`state`) <= 4096)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "67448cd997fb5b8d9e82aac9d0d5f2c61e1ea0b6302d5ddb12e3a39fd615ffe2"
    },
    {
      "name": "notification_slots",
      "primary": [
        "site_id",
        "event"
      ],
      "sql": "CREATE TABLE `notification_slots` (\n  `site_id` bigint NOT NULL,\n  `event` varbinary(32) NOT NULL,\n  `event_id` varbinary(128) NOT NULL,\n  `source_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `channel_revision` bigint NOT NULL,\n  `payload` longtext NOT NULL,\n  `private_cipher` longblob NULL,\n  `created_at` bigint NOT NULL,\n  `expires_at` bigint NOT NULL,\n  `due_at` bigint NOT NULL,\n  `status` varbinary(16) NOT NULL,\n  `attempts` bigint NOT NULL DEFAULT 0,\n  `claim` longblob NULL,\n  `lease_until` bigint NULL,\n  `last_code` varbinary(64) NULL,\n  `http_status` bigint NULL,\n  `acknowledged_at` bigint NULL,\n  `coalesced` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`site_id`,`event`),\n  KEY `notification_due` (`status`,`due_at`),\n  KEY `notification_eligible` (`status`,`channel_revision`,`expires_at`,`due_at`),\n  CONSTRAINT `notification_slots_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `notification_slots_c1` CHECK (`event` IN ('unavailable','recovery','version_lag')),\n  CONSTRAINT `notification_slots_c2` CHECK (`source_id` > 0),\n  CONSTRAINT `notification_slots_c3` CHECK (`config_revision` >= 0),\n  CONSTRAINT `notification_slots_c4` CHECK (`channel_revision` >= 0),\n  CONSTRAINT `notification_slots_c5` CHECK (OCTET_LENGTH(`payload`) <= 8192),\n  CONSTRAINT `notification_slots_c6` CHECK (`status` IN ('pending','inflight','sent','failed','cancelled','expired')),\n  CONSTRAINT `notification_slots_c7` CHECK (`attempts` BETWEEN 0 AND 3),\n  CONSTRAINT `notification_slots_c8` CHECK (`http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `notification_slots_c9` CHECK (`coalesced` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin",
      "sha256": "adce026b333a9bd612d5196d98477cb350141fcc080f672d622ff997b8c34062"
    }
  ]
}
MYSQL_LOGICAL9_D0,
        'mariadb' => <<<'MARIADB_LOGICAL9_D0'
{
  "schema": "tablo/synthetic-logical9-fixture/v1",
  "engine": "mariadb",
  "metadataFormat": 1,
  "logicalVersion": 9,
  "domainTables": 14,
  "provenance": "Frozen reviewed D0 synthetic physical9 contract, not a historical deployment or an independent SQL semantic oracle; literal CREATE statements, not current bootstrap followed by dropping a table.",
  "layoutChecksum": "94a0924d22edc7a4beed0df471a22d9d155241fc5d8b46508719a6b0f47d7eea",
  "tables": [
    {
      "name": "tablo_migration_journal",
      "primary": [
        "plan",
        "step"
      ],
      "sql": "CREATE TABLE `tablo_migration_journal` (\n  `plan` varbinary(64) NOT NULL,\n  `step` bigint NOT NULL,\n  `checksum` varbinary(64) NOT NULL,\n  `predecessor` bigint NOT NULL,\n  `target` bigint NOT NULL,\n  `before_predicate` varbinary(96) NOT NULL,\n  `after_predicate` varbinary(96) NOT NULL,\n  `phase` varbinary(16) NOT NULL,\n  `cursor` longblob NULL,\n  `failure_category` varbinary(32) NULL,\n  PRIMARY KEY (`plan`,`step`),\n  CONSTRAINT `tablo_migration_journal_c1` CHECK (`step` >= 0),\n  CONSTRAINT `tablo_migration_journal_c2` CHECK (`phase` IN ('intent','done','failed'))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "b1c9f278b1fc21ae37354e173ea8d6edcad7f22026a1183b644956ec32a3f13d"
    },
    {
      "name": "tablo_database_state",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `tablo_database_state` (\n  `id` bigint NOT NULL,\n  `format` bigint NOT NULL,\n  `logical_version` bigint NOT NULL,\n  `layout_checksum` varbinary(64) NOT NULL,\n  `phase` varbinary(16) NOT NULL,\n  `installation_id` varbinary(32) NOT NULL,\n  `key_verifier` varbinary(64) NOT NULL,\n  `committed_history_high_watermark` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `tablo_database_state_c1` CHECK (`id` = 1),\n  CONSTRAINT `tablo_database_state_c2` CHECK (`format` = 1),\n  CONSTRAINT `tablo_database_state_c3` CHECK (`logical_version` BETWEEN 0 AND 10),\n  CONSTRAINT `tablo_database_state_c4` CHECK (`phase` IN ('initializing','migrating','importing','failed','ready')),\n  CONSTRAINT `tablo_database_state_c5` CHECK (`committed_history_high_watermark` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "3cac6fac7a9fbc8d44cf816072db10ca4683ac513ae24a3563f76c3524a3abe4"
    },
    {
      "name": "tablo_writer_guard",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `tablo_writer_guard` (\n  `id` bigint NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `tablo_writer_guard_c1` CHECK (`id` = 1)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "60feefdfd8a7714e3ebbc7fea50acaae91bce170e12c5256cce95147347b8219"
    },
    {
      "name": "users",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `users` (\n  `id` bigint NOT NULL,\n  `password_hash` longblob NOT NULL,\n  `created_at` varbinary(20) NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `users_c1` CHECK (`id` = 1),\n  CONSTRAINT `users_c2` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "d40f6170af6edc283faa2f160d66f87ec47b55091787b91e882e9d7e3bc26fb2"
    },
    {
      "name": "git_tokens",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `git_tokens` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `name` varchar(80) NOT NULL,\n  `provider` varbinary(32) NOT NULL,\n  `encrypted_token` longblob NOT NULL,\n  `created_at` varbinary(20) NOT NULL,\n  `updated_at` varbinary(20) NOT NULL,\n  `name_key` varbinary(320) GENERATED ALWAYS AS (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CAST(`name` AS BINARY),X'41',X'61'),X'42',X'62'),X'43',X'63'),X'44',X'64'),X'45',X'65'),X'46',X'66'),X'47',X'67'),X'48',X'68'),X'49',X'69'),X'4a',X'6a'),X'4b',X'6b'),X'4c',X'6c'),X'4d',X'6d'),X'4e',X'6e'),X'4f',X'6f'),X'50',X'70'),X'51',X'71'),X'52',X'72'),X'53',X'73'),X'54',X'74'),X'55',X'75'),X'56',X'76'),X'57',X'77'),X'58',X'78'),X'59',X'79'),X'5a',X'7a')) STORED,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `git_tokens_provider_name` (`provider`,`name_key`),\n  CONSTRAINT `git_tokens_c1` CHECK (OCTET_LENGTH(`name`) BETWEEN 1 AND 320 AND LOCATE(X'00',CAST(`name` AS BINARY)) = 0),\n  CONSTRAINT `git_tokens_c2` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `git_tokens_c3` CHECK (OCTET_LENGTH(`updated_at`) = 20 AND ASCII(SUBSTRING(`updated_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`updated_at`,5,1) = '-' AND SUBSTRING(`updated_at`,8,1) = '-' AND SUBSTRING(`updated_at`,11,1) = 'T' AND SUBSTRING(`updated_at`,14,1) = ':' AND SUBSTRING(`updated_at`,17,1) = ':' AND SUBSTRING(`updated_at`,20,1) = 'Z' AND CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`updated_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`updated_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`updated_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "a12f3dc87782a79dd749643f340230c32efa1fab80db3fada49c9d9b90101a48"
    },
    {
      "name": "sites",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `sites` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `name` longtext NOT NULL,\n  `url` longtext NOT NULL,\n  `provider` varbinary(16) NOT NULL DEFAULT 'github',\n  `repository` longtext NOT NULL,\n  `github_token` longblob NULL,\n  `git_token_id` bigint NULL,\n  `branch` longtext NOT NULL DEFAULT ('main'),\n  `health_path` longtext NOT NULL DEFAULT ('/up'),\n  `version_path` longtext NOT NULL DEFAULT (''),\n  `version_json_path` longtext NOT NULL DEFAULT (''),\n  `health_check_mode` varbinary(16) NOT NULL DEFAULT 'http',\n  `health_json_path` longtext NOT NULL DEFAULT (''),\n  `health_json_operator` varbinary(16) NOT NULL DEFAULT '==',\n  `health_json_expected_value` longtext NOT NULL DEFAULT (''),\n  `comparison_mode` varbinary(16) NOT NULL DEFAULT 'release',\n  `enabled` bigint NOT NULL DEFAULT 1,\n  `sort_order` bigint NOT NULL DEFAULT 0,\n  `online` bigint NULL,\n  `deployed_version` longtext NULL,\n  `deployed_commit` varbinary(256) NULL,\n  `latest_release` longtext NULL,\n  `latest_commit` varbinary(256) NULL,\n  `open_issues` bigint NULL,\n  `open_prs` bigint NULL,\n  `response_time_ms` bigint NULL,\n  `last_error` longtext NULL,\n  `checked_at` varbinary(20) NULL,\n  `created_at` varbinary(20) NOT NULL,\n  `updated_at` varbinary(20) NOT NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `config_revision` bigint NOT NULL DEFAULT 0,\n  `endpoint_token` longblob NULL,\n  PRIMARY KEY (`id`),\n  KEY `sites_git_token` (`git_token_id`),\n  CONSTRAINT `sites_git_token_id_fk` FOREIGN KEY (`git_token_id`) REFERENCES `git_tokens` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,\n  CONSTRAINT `sites_c1` CHECK (`provider` IN ('github')),\n  CONSTRAINT `sites_c2` CHECK (`health_check_mode` IN ('http','json')),\n  CONSTRAINT `sites_c3` CHECK (`health_json_operator` IN ('>','>=','<','<=','!=','==','contains')),\n  CONSTRAINT `sites_c4` CHECK (`comparison_mode` IN ('release','branch')),\n  CONSTRAINT `sites_c5` CHECK (`enabled` IN (0,1)),\n  CONSTRAINT `sites_c6` CHECK (`online` IN (0,1)),\n  CONSTRAINT `sites_c7` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `sites_c8` CHECK (`config_revision` >= 0),\n  CONSTRAINT `sites_c9` CHECK (OCTET_LENGTH(`created_at`) = 20 AND ASCII(SUBSTRING(`created_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`created_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`created_at`,5,1) = '-' AND SUBSTRING(`created_at`,8,1) = '-' AND SUBSTRING(`created_at`,11,1) = 'T' AND SUBSTRING(`created_at`,14,1) = ':' AND SUBSTRING(`created_at`,17,1) = ':' AND SUBSTRING(`created_at`,20,1) = 'Z' AND CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`created_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`created_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`created_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`created_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`created_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`created_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `sites_c10` CHECK (OCTET_LENGTH(`updated_at`) = 20 AND ASCII(SUBSTRING(`updated_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`updated_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`updated_at`,5,1) = '-' AND SUBSTRING(`updated_at`,8,1) = '-' AND SUBSTRING(`updated_at`,11,1) = 'T' AND SUBSTRING(`updated_at`,14,1) = ':' AND SUBSTRING(`updated_at`,17,1) = ':' AND SUBSTRING(`updated_at`,20,1) = 'Z' AND CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`updated_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`updated_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`updated_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`updated_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`updated_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`updated_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `sites_c11` CHECK (OCTET_LENGTH(`checked_at`) = 20 AND ASCII(SUBSTRING(`checked_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`checked_at`,5,1) = '-' AND SUBSTRING(`checked_at`,8,1) = '-' AND SUBSTRING(`checked_at`,11,1) = 'T' AND SUBSTRING(`checked_at`,14,1) = ':' AND SUBSTRING(`checked_at`,17,1) = ':' AND SUBSTRING(`checked_at`,20,1) = 'Z' AND CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`checked_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`checked_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`checked_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "d7c945cc592c05ad2002aa81b88ae7f2384f38486ed920d967260b24c8968972"
    },
    {
      "name": "login_limits",
      "primary": [
        "key"
      ],
      "sql": "CREATE TABLE `login_limits` (\n  `key` varbinary(128) NOT NULL,\n  `failures` bigint NOT NULL DEFAULT 0,\n  `window_start` bigint NOT NULL,\n  PRIMARY KEY (`key`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "4f774f7df59bae3b3716aff3cc5c458dda796d0aea400c8c2eca52e7412def6b"
    },
    {
      "name": "github_cooldowns",
      "primary": [
        "scope",
        "resource"
      ],
      "sql": "CREATE TABLE `github_cooldowns` (\n  `scope` varbinary(256) NOT NULL,\n  `resource` varbinary(16) NOT NULL,\n  `eligible_at` bigint NOT NULL,\n  PRIMARY KEY (`scope`,`resource`),\n  CONSTRAINT `github_cooldowns_c1` CHECK (`resource` IN ('core','search','secondary')),\n  CONSTRAINT `github_cooldowns_c2` CHECK (`eligible_at` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "d420440c5b5444c4c78436eeb33f124b3ce93ba94f68b879362968f1455702de"
    },
    {
      "name": "installation_settings",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `installation_settings` (\n  `id` bigint NOT NULL,\n  `check_interval_minutes` bigint NOT NULL DEFAULT 10,\n  `session_idle_timeout_minutes` bigint NOT NULL DEFAULT 30,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `installation_settings_c1` CHECK (`id` = 1),\n  CONSTRAINT `installation_settings_c2` CHECK (`check_interval_minutes` BETWEEN 1 AND 153722867280912930),\n  CONSTRAINT `installation_settings_c3` CHECK (`session_idle_timeout_minutes` BETWEEN 1 AND 720)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "602d7c5ab1f7e583b4e588fd8a196edd6d72d809df1b86979434f9f51c31f4fe"
    },
    {
      "name": "worker_runtime",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `worker_runtime` (\n  `id` bigint NOT NULL,\n  `run_id` longblob NULL,\n  `stop_requested` bigint NOT NULL DEFAULT 0,\n  `fairness_turn` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `worker_runtime_c1` CHECK (`id` = 1),\n  CONSTRAINT `worker_runtime_c2` CHECK (`stop_requested` IN (0,1)),\n  CONSTRAINT `worker_runtime_c3` CHECK (`fairness_turn` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "258f872cd78171046e5aa3daef147cc83ee9325ca1e1921794af4c3ac8a4a7b5"
    },
    {
      "name": "worker_progress",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `worker_progress` (\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `latest_release` bigint NOT NULL DEFAULT 0,\n  `latest_commit` bigint NOT NULL DEFAULT 0,\n  `open_issues` bigint NOT NULL DEFAULT 0,\n  `open_prs` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `worker_progress_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `worker_progress_c1` CHECK (`config_revision` >= 0),\n  CONSTRAINT `worker_progress_c2` CHECK (`latest_release` >= 0),\n  CONSTRAINT `worker_progress_c3` CHECK (`latest_commit` >= 0),\n  CONSTRAINT `worker_progress_c4` CHECK (`open_issues` >= 0),\n  CONSTRAINT `worker_progress_c5` CHECK (`open_prs` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "f4de8edab76b40fc71558a0a1fcc67e83dc7c4d070b155eb64175ea3b1c85c51"
    },
    {
      "name": "check_history",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `check_history` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `checked_at` varbinary(20) NOT NULL,\n  `online` bigint NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `response_time_ms` bigint NULL,\n  `deployed_version` varbinary(200) NULL,\n  `deployed_commit` varbinary(64) NULL,\n  `latest_release` varbinary(200) NULL,\n  `latest_commit` varbinary(64) NULL,\n  `version_status` varbinary(16) NOT NULL,\n  `version_error_code` varbinary(64) NULL,\n  `version_http_status` bigint NULL,\n  `release_error_code` varbinary(64) NULL,\n  `release_http_status` bigint NULL,\n  `commit_error_code` varbinary(64) NULL,\n  `commit_http_status` bigint NULL,\n  PRIMARY KEY (`id`),\n  KEY `check_history_site_time` (`site_id`,`checked_at`,`id`),\n  KEY `check_history_time` (`checked_at`,`id`),\n  CONSTRAINT `check_history_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `check_history_c1` CHECK (`config_revision` >= 0),\n  CONSTRAINT `check_history_c2` CHECK (OCTET_LENGTH(`checked_at`) = 20 AND ASCII(SUBSTRING(`checked_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`checked_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`checked_at`,5,1) = '-' AND SUBSTRING(`checked_at`,8,1) = '-' AND SUBSTRING(`checked_at`,11,1) = 'T' AND SUBSTRING(`checked_at`,14,1) = ':' AND SUBSTRING(`checked_at`,17,1) = ':' AND SUBSTRING(`checked_at`,20,1) = 'Z' AND CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`checked_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`checked_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`checked_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`checked_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`checked_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`checked_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `check_history_c3` CHECK (`online` IN (0,1)),\n  CONSTRAINT `check_history_c4` CHECK (`health_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition','redirect-location-missing','redirect-location-invalid','redirect-origin','redirect-cycle','redirect-limit')),\n  CONSTRAINT `check_history_c5` CHECK (`response_time_ms` BETWEEN 0 AND 2147483647),\n  CONSTRAINT `check_history_c6` CHECK (`version_status` IN ('skipped','ok','error')),\n  CONSTRAINT `check_history_c7` CHECK (`version_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition','invalid-data','redirect-location-missing','redirect-location-invalid','redirect-origin','redirect-cycle','redirect-limit')),\n  CONSTRAINT `check_history_c8` CHECK (`release_error_code` IN ('access','rate-limit','budget','credential-changed','renamed','branch-unconfirmed','release-unconfirmed','incomplete-search','invalid-data','unavailable','check-error')),\n  CONSTRAINT `check_history_c9` CHECK (`commit_error_code` IN ('access','rate-limit','budget','credential-changed','renamed','branch-unconfirmed','release-unconfirmed','incomplete-search','invalid-data','unavailable','check-error')),\n  CONSTRAINT `check_history_c10` CHECK ((`version_status` = 'error') = (`version_error_code` IS NOT NULL)),\n  CONSTRAINT `check_history_c11` CHECK (OCTET_LENGTH(`deployed_commit`) BETWEEN 7 AND 64 AND OCTET_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`deployed_commit`,'0',''),'1',''),'2',''),'3',''),'4',''),'5',''),'6',''),'7',''),'8',''),'9',''),'a',''),'b',''),'c',''),'d',''),'e',''),'f','')) = 0),\n  CONSTRAINT `check_history_c12` CHECK (OCTET_LENGTH(`latest_commit`) BETWEEN 7 AND 64 AND OCTET_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`latest_commit`,'0',''),'1',''),'2',''),'3',''),'4',''),'5',''),'6',''),'7',''),'8',''),'9',''),'a',''),'b',''),'c',''),'d',''),'e',''),'f','')) = 0),\n  CONSTRAINT `check_history_c13` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c14` CHECK (`version_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c15` CHECK (`release_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c16` CHECK (`commit_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `check_history_c17` CHECK (OCTET_LENGTH(`deployed_version`) BETWEEN 1 AND 200),\n  CONSTRAINT `check_history_c18` CHECK (OCTET_LENGTH(`latest_release`) BETWEEN 1 AND 200)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "13ab12db1056e957d1c0f17a1991c2b8e8768db6da707ef7939a649d11b5c296"
    },
    {
      "name": "incidents",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `incidents` (\n  `id` bigint NOT NULL,\n  `site_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `opened_at` varbinary(20) NOT NULL,\n  `health_error_code` varbinary(64) NULL,\n  `health_http_status` bigint NULL,\n  `recovery_history_id` bigint NULL,\n  `recovered_at` varbinary(20) NULL,\n  `interruption_history_id` bigint NULL,\n  `end_reason` varbinary(32) NULL,\n  `uncertain` bigint NOT NULL DEFAULT 0,\n  `clock_invalid` bigint NOT NULL DEFAULT 0,\n  `unresolved_site_id` bigint GENERATED ALWAYS AS (CASE WHEN `end_reason` IS NULL THEN `site_id` ELSE NULL END) VIRTUAL,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `incidents_unresolved` (`unresolved_site_id`),\n  KEY `incidents_site_id` (`site_id`,`id`),\n  CONSTRAINT `incidents_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `incidents_c1` CHECK (`id` > 0),\n  CONSTRAINT `incidents_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `incidents_c3` CHECK (OCTET_LENGTH(`opened_at`) = 20 AND ASCII(SUBSTRING(`opened_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`opened_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`opened_at`,5,1) = '-' AND SUBSTRING(`opened_at`,8,1) = '-' AND SUBSTRING(`opened_at`,11,1) = 'T' AND SUBSTRING(`opened_at`,14,1) = ':' AND SUBSTRING(`opened_at`,17,1) = ':' AND SUBSTRING(`opened_at`,20,1) = 'Z' AND CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`opened_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`opened_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`opened_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`opened_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`opened_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`opened_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `incidents_c4` CHECK (OCTET_LENGTH(`recovered_at`) = 20 AND ASCII(SUBSTRING(`recovered_at`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`recovered_at`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`recovered_at`,5,1) = '-' AND SUBSTRING(`recovered_at`,8,1) = '-' AND SUBSTRING(`recovered_at`,11,1) = 'T' AND SUBSTRING(`recovered_at`,14,1) = ':' AND SUBSTRING(`recovered_at`,17,1) = ':' AND SUBSTRING(`recovered_at`,20,1) = 'Z' AND CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`recovered_at`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`recovered_at`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`recovered_at`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`recovered_at`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`recovered_at`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`recovered_at`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12)))),\n  CONSTRAINT `incidents_c5` CHECK (`health_error_code` IN ('http','timeout','refused','dns','ssrf','tls','size','invalid-response','invalid-url','network','check-error','json-condition')),\n  CONSTRAINT `incidents_c6` CHECK (`health_http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `incidents_c7` CHECK (`recovery_history_id` > `id`),\n  CONSTRAINT `incidents_c8` CHECK (`interruption_history_id` > `id`),\n  CONSTRAINT `incidents_c9` CHECK (`end_reason` IN ('recovered','config-changed')),\n  CONSTRAINT `incidents_c10` CHECK (`uncertain` IN (0,1)),\n  CONSTRAINT `incidents_c11` CHECK (`clock_invalid` IN (0,1)),\n  CONSTRAINT `incidents_c12` CHECK ((`end_reason` <=> 'recovered') = (`recovery_history_id` IS NOT NULL AND `recovered_at` IS NOT NULL)),\n  CONSTRAINT `incidents_c13` CHECK ((`end_reason` <=> 'config-changed') = (`interruption_history_id` IS NOT NULL)),\n  CONSTRAINT `incidents_c14` CHECK ((`end_reason` <=> 'recovered') OR (`recovery_history_id` IS NULL AND `recovered_at` IS NULL))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "5e57c1acc44d72dc03a29c8a1b5fcd214fb69c623c4150a63e01be6f718fd460"
    },
    {
      "name": "incident_checkpoints",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `incident_checkpoints` (\n  `site_id` bigint NOT NULL,\n  `last_history_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `watermark` varbinary(20) NOT NULL,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `incident_checkpoints_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `incident_checkpoints_c1` CHECK (`last_history_id` > 0),\n  CONSTRAINT `incident_checkpoints_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `incident_checkpoints_c3` CHECK (OCTET_LENGTH(`watermark`) = 20 AND ASCII(SUBSTRING(`watermark`,1,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,2,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,3,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,4,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,6,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,7,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,9,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,10,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,12,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,13,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,15,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,16,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,18,1)) BETWEEN 48 AND 57 AND ASCII(SUBSTRING(`watermark`,19,1)) BETWEEN 48 AND 57 AND SUBSTRING(`watermark`,5,1) = '-' AND SUBSTRING(`watermark`,8,1) = '-' AND SUBSTRING(`watermark`,11,1) = 'T' AND SUBSTRING(`watermark`,14,1) = ':' AND SUBSTRING(`watermark`,17,1) = ':' AND SUBSTRING(`watermark`,20,1) = 'Z' AND CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED) BETWEEN 1 AND 9999 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) BETWEEN 1 AND 12 AND CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) BETWEEN 1 AND 31 AND CAST(SUBSTRING(`watermark`,12,2) AS UNSIGNED) BETWEEN 0 AND 23 AND CAST(SUBSTRING(`watermark`,15,2) AS UNSIGNED) BETWEEN 0 AND 59 AND CAST(SUBSTRING(`watermark`,18,2) AS UNSIGNED) BETWEEN 0 AND 59 AND (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) <= 28 OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 29 AND (CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) <> 2 OR (MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),4) = 0 AND (MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),100) <> 0 OR MOD(CAST(SUBSTRING(`watermark`,1,4) AS UNSIGNED),400) = 0)))) OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 30 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) <> 2) OR (CAST(SUBSTRING(`watermark`,9,2) AS UNSIGNED) = 31 AND CAST(SUBSTRING(`watermark`,6,2) AS UNSIGNED) IN (1,3,5,7,8,10,12))))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "705aa5c7e910209bd7f2cabef1294ac3e2fb256d071d7fb9c87c967ed4dff190"
    },
    {
      "name": "notification_settings",
      "primary": [
        "id"
      ],
      "sql": "CREATE TABLE `notification_settings` (\n  `id` bigint NOT NULL,\n  `installation_id` varbinary(32) NOT NULL,\n  `revision` bigint NOT NULL DEFAULT 0,\n  `activation_id` bigint NOT NULL DEFAULT 0,\n  `enabled` bigint NOT NULL DEFAULT 0,\n  `blocked` bigint NOT NULL DEFAULT 0,\n  `endpoint_cipher` longblob NULL,\n  `bearer_cipher` longblob NULL,\n  `unavailable` bigint NOT NULL DEFAULT 1,\n  `recovery` bigint NOT NULL DEFAULT 1,\n  `version_lag` bigint NOT NULL DEFAULT 1,\n  `include_name` bigint NOT NULL DEFAULT 0,\n  `include_url` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `notification_settings_c1` CHECK (`id` = 1),\n  CONSTRAINT `notification_settings_c2` CHECK (OCTET_LENGTH(`installation_id`) = 32),\n  CONSTRAINT `notification_settings_c3` CHECK (`revision` >= 0),\n  CONSTRAINT `notification_settings_c4` CHECK (`activation_id` >= 0),\n  CONSTRAINT `notification_settings_c5` CHECK (`enabled` IN (0,1)),\n  CONSTRAINT `notification_settings_c6` CHECK (`blocked` IN (0,1)),\n  CONSTRAINT `notification_settings_c7` CHECK (`unavailable` IN (0,1)),\n  CONSTRAINT `notification_settings_c8` CHECK (`recovery` IN (0,1)),\n  CONSTRAINT `notification_settings_c9` CHECK (`version_lag` IN (0,1)),\n  CONSTRAINT `notification_settings_c10` CHECK (`include_name` IN (0,1)),\n  CONSTRAINT `notification_settings_c11` CHECK (`include_url` IN (0,1))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "e0540c9a68324a740d887dd5c4247b93983980292ffba8d5d0d9d821d49b816c"
    },
    {
      "name": "notification_checkpoints",
      "primary": [
        "site_id"
      ],
      "sql": "CREATE TABLE `notification_checkpoints` (\n  `site_id` bigint NOT NULL,\n  `last_history_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `state` longtext NOT NULL,\n  PRIMARY KEY (`site_id`),\n  CONSTRAINT `notification_checkpoints_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `notification_checkpoints_c1` CHECK (`last_history_id` > 0),\n  CONSTRAINT `notification_checkpoints_c2` CHECK (`config_revision` >= 0),\n  CONSTRAINT `notification_checkpoints_c3` CHECK (OCTET_LENGTH(`state`) <= 4096)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "8e7aecbd45715aa80cc5237831a9e6fa17418b3e257141c84f1a313926798bb4"
    },
    {
      "name": "notification_slots",
      "primary": [
        "site_id",
        "event"
      ],
      "sql": "CREATE TABLE `notification_slots` (\n  `site_id` bigint NOT NULL,\n  `event` varbinary(32) NOT NULL,\n  `event_id` varbinary(128) NOT NULL,\n  `source_id` bigint NOT NULL,\n  `config_revision` bigint NOT NULL,\n  `channel_revision` bigint NOT NULL,\n  `payload` longtext NOT NULL,\n  `private_cipher` longblob NULL,\n  `created_at` bigint NOT NULL,\n  `expires_at` bigint NOT NULL,\n  `due_at` bigint NOT NULL,\n  `status` varbinary(16) NOT NULL,\n  `attempts` bigint NOT NULL DEFAULT 0,\n  `claim` longblob NULL,\n  `lease_until` bigint NULL,\n  `last_code` varbinary(64) NULL,\n  `http_status` bigint NULL,\n  `acknowledged_at` bigint NULL,\n  `coalesced` bigint NOT NULL DEFAULT 0,\n  PRIMARY KEY (`site_id`,`event`),\n  KEY `notification_due` (`status`,`due_at`),\n  KEY `notification_eligible` (`status`,`channel_revision`,`expires_at`,`due_at`),\n  CONSTRAINT `notification_slots_site_id_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,\n  CONSTRAINT `notification_slots_c1` CHECK (`event` IN ('unavailable','recovery','version_lag')),\n  CONSTRAINT `notification_slots_c2` CHECK (`source_id` > 0),\n  CONSTRAINT `notification_slots_c3` CHECK (`config_revision` >= 0),\n  CONSTRAINT `notification_slots_c4` CHECK (`channel_revision` >= 0),\n  CONSTRAINT `notification_slots_c5` CHECK (OCTET_LENGTH(`payload`) <= 8192),\n  CONSTRAINT `notification_slots_c6` CHECK (`status` IN ('pending','inflight','sent','failed','cancelled','expired')),\n  CONSTRAINT `notification_slots_c7` CHECK (`attempts` BETWEEN 0 AND 3),\n  CONSTRAINT `notification_slots_c8` CHECK (`http_status` BETWEEN 100 AND 599),\n  CONSTRAINT `notification_slots_c9` CHECK (`coalesced` >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin",
      "sha256": "28531d7faa25c642b510896ad805006f291b0b93bf6d31ab8bc6f1997ef1348d"
    }
  ]
}
MARIADB_LOGICAL9_D0,
        default => throw new \RuntimeException('Explicit predecessor fixture engine required.'),
    };
    return JsonRecord::decode($json);
}

/** The actual sealed owner keeps one immutable complete source and native per-record decoding.
 * @param \Tablo\ServerSchema::Layout $golden */
function engine_schema_admission_records_contract(string $bytes, string $engine, array $golden): void
{
    $loader = engine_schema_admission_records_sealed();
    $reference = \Tablo\ServerSchemaDescriptor::admissionTables($golden);
    foreach ([$bytes, str_replace("\n", replace: "\r\n", subject: $bytes)] as $input) {
        engine_schema_admission_records_positive($loader, $input, $engine, $reference);
    }
    engine_schema_admission_records_integrity($loader, $bytes, $engine);
    engine_schema_admission_records_framing($loader, $bytes, $engine);
    engine_schema_admission_records_io($loader, $bytes, $engine);
    engine_schema_admission_records_native();
}

function engine_schema_admission_records_sealed(): \ReflectionMethod
{
    $class = new \ReflectionClass(\Tablo\ServerAdmissionRecords::class);
    Assert::true($class->isFinal()); Assert::true($class->isReadOnly());
    $constructor = $class->getConstructor() ?? throw new \RuntimeException('Missing source owner constructor.');
    Assert::true($constructor->isPrivate());
    $raw = $class->getProperty('records'); Assert::true($raw->isPrivate()); Assert::true($raw->isReadOnly());
    $loader = new \ReflectionMethod(\Tablo\ServerAdmissionRecords::class, 'fromSource');
    Assert::true($loader->isPrivate());
    return $loader;
}

/** @param list<\Tablo\ServerSchema::AdmissionTable> $reference */
function engine_schema_admission_records_positive(\ReflectionMethod $loader, string $input, string $engine, array $reference): void
{
    $result = engine_schema_admission_records_source($loader, $input, $engine);
    $owner = $result['owner'] ?? throw new \RuntimeException('Missing validated source owner.');
    Assert::same($result['failure'], null); Assert::same($result['read'], strlen($input));
    $owner->assertEngine($engine); $names = $owner->names();
    Assert::same($names, array_column($reference, 'name')); $names[0] = 'caller-change';
    Assert::same($owner->names(), array_column($reference, 'name'));
    EngineSchemaDescriptorStream::$bytes = '';
    foreach ([9, 10] as $version) {
        $expected = array_values(array_filter($reference, static fn (array $table): bool => $version === 10 || $table['name'] !== 'read_api_token'));
        Assert::same(iterator_to_array($owner->tables($version), preserve_keys: false), $expected);
        Assert::same($owner->names($version), array_column($expected, 'name'));
    }
    Assert::same(EngineSchemaDescriptorStream::$opens, 1); Assert::same(EngineSchemaDescriptorStream::$closes, 1);
    Assert::same(iterator_to_array($owner->tables(), preserve_keys: false), $reference, 'iteration never rereads a mutable source or returns a consumed cache');
}

/** @param array{failAt?: ?int, failClose?: bool, failOpen?: bool} $options
 * @return array{owner: ?\Tablo\ServerAdmissionRecords, failure: ?\Tablo\DatabaseFailure, read: int} */
function engine_schema_admission_records_source(\ReflectionMethod $loader, string $bytes, string $engine, array $options = []): array
{
    EngineSchemaDescriptorStream::$bytes = $bytes; EngineSchemaDescriptorStream::$position = 0;
    EngineSchemaDescriptorStream::$opens = 0; EngineSchemaDescriptorStream::$closes = 0;
    EngineSchemaDescriptorStream::$failAt = $options['failAt'] ?? null; EngineSchemaDescriptorStream::$failClose = $options['failClose'] ?? false;
    $failOpen = $options['failOpen'] ?? false; EngineSchemaDescriptorStream::$failOpen = $failOpen;
    $readers = array_keys(get_resources('stream')); $owner = null; $failure = null;
    try {
        /** @var mixed $actual Reflection must be narrowed by the runtime instance assertion. */
        $actual = $loader->invoke(null, 'tabloschemafixture://descriptor-private-records-sentinel', $engine);
        Assert::instanceOf($actual, \Tablo\ServerAdmissionRecords::class); $owner = $actual;
    } catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
    finally {
        Assert::same(EngineSchemaDescriptorStream::$opens, 1); Assert::same(EngineSchemaDescriptorStream::$closes, $failOpen ? 0 : 1);
        Assert::same(array_keys(get_resources('stream')), $readers);
    }
    if ($failure !== null) { engine_schema_descriptor_refusal($failure); }
    Assert::same(EngineSchemaDescriptorStream::$bytes, $bytes);
    return ['owner' => $owner, 'failure' => $failure, 'read' => EngineSchemaDescriptorStream::$position];
}

function engine_schema_admission_records_integrity(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    $lines = explode("\n", $bytes);
    foreach ([0, 8, 17] as $index) {
        foreach (['sha', 'create-type', 'checksum-type', 'name-type'] as $fault) {
            $changed = $lines;
            $changed[1 + $index] = engine_schema_admission_records_fault($changed[1 + $index] ?? '', $fault);
            $input = implode("\n", $changed);
            engine_schema_admission_records_invalid($loader, $input, $engine, expectedRead: strlen($input));
        }
    }
    foreach ([str_replace('"format":1', replace: '"format":0', subject: $bytes),
        str_replace('"engine":"' . $engine . '"', replace: '"engine":"other"', subject: $bytes)] as $input) {
        engine_schema_admission_records_invalid($loader, $input, $engine, expectedRead: strlen($input));
    }
}

function engine_schema_admission_records_fault(string $record, string $fault): string
{
    $table = \Tablo\server_schema_descriptor_table(JsonRecord::checked(json_decode($record, associative: true, flags: JSON_THROW_ON_ERROR)));
    if ($fault === 'create-type') { $table['create'] = null; }
    if ($fault === 'name-type') { $table['name'] = null; }
    if ($fault !== 'name-type') { $table['checksum'] = $fault === 'checksum-type' ? null : str_repeat('0', times: 64); }
    return \Tablo\JsonEncoding::encode(['tables' => [$table]]);
}

function engine_schema_admission_records_invalid(\ReflectionMethod $loader, string $input, string $engine, ?int $expectedRead = null): void
{
    $actual = engine_schema_admission_records_source($loader, $input, $engine);
    engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['owner'], null);
    if ($expectedRead !== null) { Assert::same($actual['read'], $expectedRead); }
}

function engine_schema_admission_records_framing(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    $lines = explode("\n", $bytes); $tail = $lines[18] ?? throw new \RuntimeException('Missing final source record.');
    foreach ([substr($bytes, offset: 0, length: -1), $bytes . "\n", $bytes . "\0", $bytes . $tail . "\n", substr($bytes, offset: 0, length: -80),
        str_replace('"records":2', replace: '"records":1', subject: $bytes), str_replace('"format":1', replace: '"format":"1"', subject: $bytes),
        str_replace('{"records":2,"layout":', replace: '{"unknown":0,"records":2,"layout":', subject: $bytes),
        str_replace($tail, replace: str_replace('{"tables":', replace: '{"other":', subject: $tail), subject: $bytes)] as $input) {
        engine_schema_admission_records_invalid($loader, $input, $engine);
    }
}

function engine_schema_admission_records_io(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    foreach ([['failAt' => 0], ['failAt' => 9000], ['failAt' => strlen($bytes)], ['failOpen' => true], ['failClose' => true]] as $options) {
        $actual = engine_schema_admission_records_source($loader, $bytes, $engine, $options);
        engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['owner'], null);
    }
    $primary = engine_schema_admission_records_source($loader, bytes: '{', engine: $engine)['failure'];
    $closed = engine_schema_admission_records_source($loader, bytes: '{', engine: $engine, options: ['failClose' => true])['failure'];
    engine_schema_descriptor_refusal($primary); engine_schema_descriptor_refusal($closed); Assert::same($closed?->getLine(), $primary?->getLine());
    engine_schema_admission_records_handler($loader, $bytes, $engine);
}

function engine_schema_admission_records_handler(\ReflectionMethod $loader, string $bytes, string $engine): void
{
    set_error_handler(static function (int $severity, string $message): never { throw new \ErrorException($message, 0, $severity); });
    try {
        engine_schema_descriptor_refusal(engine_schema_admission_records_source($loader, $bytes, $engine, options: ['failAt' => 9000])['failure']);
        $restored = false;
        try { trigger_error('descriptor-private-records-handler-sentinel', E_USER_WARNING); } catch (\ErrorException) { $restored = true; }
        Assert::true($restored);
    } finally { restore_error_handler(); }
}

/** The new per-record decoder keeps the complete wrapped native JSON contract. */
function engine_schema_admission_records_native(): void
{
    $special = <<<'JSON'
"quote\" slash\/ backslash\\ control\u0000\r\n\t Caf\u00e9 \ud83e\uddea"
JSON;
    foreach (['null', 'false', 'true', '0', '1', '-1', '1.0', '1.5', '-0.0', '1e2', '"1"', '""', '[]', '{}',
        '{"3":"three","1":"one"}', '[null,false,true,0,"1",[],{"nested":[]}]', $special] as $value) {
        $record = engine_schema_descriptor_table_record('columns', $value) . "\n";
        Assert::same(\Tablo\server_admission_records_table($record),
            \Tablo\server_schema_descriptor_table(JsonRecord::checked(json_decode($record, associative: true, flags: JSON_THROW_ON_ERROR))));
    }
}

/** Actual streamed source checksum retains the independent golden codec and source-custody controls. */
function engine_schema_source_checksum_contract(\ReflectionMethod $loader, string $bytes, string $engine, int $version, string $reference): void
{
    $before = $bytes;
    foreach ([$bytes, str_replace("\n", replace: "\r\n", subject: $bytes)] as $input) {
        $actual = engine_schema_source_checksum_result($loader, $input, $engine, $version);
        Assert::same($actual['checksum'], $reference); Assert::same($actual['failure'], null);
        Assert::same($actual['read'], strlen($input));
    }
    engine_schema_source_checksum_integrity($loader, $bytes, $engine, $version);
    engine_schema_source_checksum_framing($loader, $bytes, $engine, $version);
    engine_schema_source_checksum_io($loader, $bytes, $engine, $version);
    engine_schema_source_checksum_encoding($loader, $bytes, $engine, $version);
    Assert::same($bytes, $before);
}

/** @param array{failAt?: ?int, failClose?: bool, failOpen?: bool} $options
 * @return array{checksum: ?string, failure: ?\Tablo\DatabaseFailure, read: int} */
function engine_schema_source_checksum_result(\ReflectionMethod $loader, string $bytes, string $engine, int $version, array $options = []): array
{
    EngineSchemaDescriptorStream::$bytes = $bytes; EngineSchemaDescriptorStream::$position = 0;
    EngineSchemaDescriptorStream::$opens = 0; EngineSchemaDescriptorStream::$closes = 0;
    EngineSchemaDescriptorStream::$failAt = $options['failAt'] ?? null; EngineSchemaDescriptorStream::$failClose = $options['failClose'] ?? false;
    $failOpen = $options['failOpen'] ?? false; EngineSchemaDescriptorStream::$failOpen = $failOpen;
    $readers = array_keys(get_resources('stream')); $checksum = null; $failure = null;
    try {
        /** @var mixed $actual Reflection must be narrowed by the native string check. */
        $actual = $loader->invoke(null, 'tabloschemafixture://descriptor-private-checksum-sentinel', $engine, $version);
        if (!is_string($actual)) { throw new \RuntimeException('Source checksum did not return a string.'); }
        $checksum = $actual;
    } catch (\Tablo\DatabaseFailure $error) { $failure = $error; }
    finally {
        Assert::same(EngineSchemaDescriptorStream::$opens, 1); Assert::same(EngineSchemaDescriptorStream::$closes, $failOpen ? 0 : 1);
        Assert::same(array_keys(get_resources('stream')), $readers);
    }
    if ($failure !== null) { engine_schema_descriptor_refusal($failure); }
    Assert::same(EngineSchemaDescriptorStream::$bytes, $bytes);
    return ['checksum' => $checksum, 'failure' => $failure, 'read' => EngineSchemaDescriptorStream::$position];
}

function engine_schema_source_checksum_integrity(\ReflectionMethod $loader, string $bytes, string $engine, int $version): void
{
    $lines = explode("\n", $bytes);
    foreach ([0, 8, 17] as $index) {
        foreach (['sha', 'create-type', 'checksum-type'] as $fault) {
            $changed = $lines;
            $table = \Tablo\server_schema_descriptor_table(JsonRecord::checked(json_decode($changed[1 + $index] ?? '', associative: true, flags: JSON_THROW_ON_ERROR)));
            if ($fault === 'create-type') { $table['create'] = null; }
            $table['checksum'] = $fault === 'checksum-type' ? null : str_repeat('0', times: 64);
            $changed[1 + $index] = \Tablo\JsonEncoding::encode(['tables' => [$table]]);
            $input = implode("\n", $changed); $actual = engine_schema_source_checksum_result($loader, $input, $engine, $version);
            engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['checksum'], null);
            Assert::same($actual['read'], strlen($input), 'all records must be consumed even when version9 excludes the invalid tail');
        }
    }
    foreach ([str_replace('"format":1', replace: '"format":0', subject: $bytes),
        str_replace('"engine":"' . $engine . '"', replace: '"engine":"other"', subject: $bytes)] as $input) {
        $actual = engine_schema_source_checksum_result($loader, $input, $engine, $version);
        engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['checksum'], null); Assert::same($actual['read'], strlen($input));
    }
}

function engine_schema_source_checksum_framing(\ReflectionMethod $loader, string $bytes, string $engine, int $version): void
{
    $lines = explode("\n", $bytes); $tail = $lines[18] ?? throw new \RuntimeException('Missing final source record.');
    foreach ([substr($bytes, offset: 0, length: -1), $bytes . "\n", $bytes . "\0", $bytes . $tail . "\n", substr($bytes, offset: 0, length: -80),
        str_replace('"records":2', replace: '"records":1', subject: $bytes), str_replace('"format":1', replace: '"format":"1"', subject: $bytes),
        str_replace('{"records":2,"layout":', replace: '{"unknown":0,"records":2,"layout":', subject: $bytes),
        str_replace($tail, replace: str_replace('{"tables":', replace: '{"other":', subject: $tail), subject: $bytes)] as $input) {
        $actual = engine_schema_source_checksum_result($loader, $input, $engine, $version);
        engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['checksum'], null);
    }
}

function engine_schema_source_checksum_io(\ReflectionMethod $loader, string $bytes, string $engine, int $version): void
{
    foreach ([['failAt' => 0], ['failAt' => 9000], ['failAt' => strlen($bytes)], ['failOpen' => true], ['failClose' => true]] as $options) {
        $actual = engine_schema_source_checksum_result($loader, $bytes, $engine, $version, $options);
        engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['checksum'], null);
    }
    $primary = engine_schema_source_checksum_result($loader, bytes: '{', engine: $engine, version: $version)['failure'];
    $closed = engine_schema_source_checksum_result($loader, bytes: '{', engine: $engine, version: $version, options: ['failClose' => true])['failure'];
    engine_schema_descriptor_refusal($primary); engine_schema_descriptor_refusal($closed); Assert::same($closed?->getLine(), $primary?->getLine());
    set_error_handler(static function (int $severity, string $message): never { throw new \ErrorException($message, 0, $severity); });
    try {
        engine_schema_descriptor_refusal(engine_schema_source_checksum_result($loader, $bytes, $engine, $version, options: ['failAt' => 9000])['failure']);
        $restored = false;
        try { trigger_error('descriptor-private-checksum-handler-sentinel', E_USER_WARNING); } catch (\ErrorException) { $restored = true; }
        Assert::true($restored);
    } finally { restore_error_handler(); }
}

/** A decodable overflowing JSON number must not change old encoding failure or source/close precedence. */
function engine_schema_source_checksum_encoding(\ReflectionMethod $loader, string $bytes, string $engine, int $version): void
{
    $lines = explode("\n", $bytes);
    $record = engine_schema_descriptor_table_record('columns', value: '[1e999]');
    $lines[1] = str_replace('"checksum":""', replace: '"checksum":"' . hash('sha256', data: '') . '"', subject: $record);
    $input = implode("\n", $lines);
    $fullLoader = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, 'sourceLayout');
    $full = engine_schema_descriptor_source($fullLoader, $input, $engine)['layout'] ?? throw new \RuntimeException('Encoding fixture was not parsed by the old full-layout path.');
    // The full reader checked each ordered table frame before this encoding-failure oracle.
    $tables = array_values(array_filter(JsonRecord::checked($full['tables'] ?? null), static fn (array $table): bool => $version === 10 || ($table['name'] ?? null) !== 'read_api_token'));
    $method = new \ReflectionMethod(\Tablo\ServerSchemaDescriptor::class, '_checksumTables');
    $expected = engine_schema_checksum_outcome(static fn () => $method->invoke(null, $tables)); Assert::true($expected['error'] !== null);
    Assert::same(engine_schema_checksum_outcome(static fn () => engine_schema_source_checksum_result($loader, $input, $engine, $version)['checksum']), $expected);
    $closed = engine_schema_source_checksum_result($loader, $input, $engine, $version, options: ['failClose' => true]);
    engine_schema_descriptor_refusal($closed['failure']); Assert::same($closed['checksum'], null);
    $last = \Tablo\server_schema_descriptor_table(JsonRecord::checked(json_decode($lines[18] ?? '', associative: true, flags: JSON_THROW_ON_ERROR)));
    $last['checksum'] = str_repeat('0', times: 64); $lines[18] = \Tablo\JsonEncoding::encode(['tables' => [$last]]);
    $actual = engine_schema_source_checksum_result($loader, implode("\n", $lines), $engine, $version);
    engine_schema_descriptor_refusal($actual['failure']); Assert::same($actual['checksum'], null);
}
