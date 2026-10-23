<?php
declare(strict_types=1);

use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\SqlColumn;
use Tablo\Tools\ServerFixtureCatalog;
use Tablo\Tools\ServerFixturePlan;
use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\SubprocessReceipt;
use Tablo\Tests\Support\TemporaryDirectory;
use function Tablo\Tools\server_fixture_plan_php_module_pins;
use function Tablo\Tools\server_fixture_plan_linux_regular;

/** A cell never adopts an existing service by its name: the frozen plan includes its saved creation ID.
 * @param array<string, string> $environment
 * @return array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} */
function server_cell_context(string $root, array $environment): array
{
    $root = str_replace('\\', replace: '/', subject: $root);
    $path = SqlColumn::string($environment, 'TABLO_TEST_CELL_PLAN');
    if (is_link($path)) { throw new RuntimeException('Linked cell plan refused.'); }
    $digest = SqlColumn::string($environment, 'TABLO_TEST_CELL_PLAN_SHA256');
    if (!hash_equals($digest, RequiredFile::digest($path))) { throw new RuntimeException('Frozen native cell plan changed.'); }
    $plan = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)), 64);
    if ([$plan['schema'] ?? null, $plan['nonce'] ?? null, $plan['source'] ?? null]
        !== ['tablo/server-cell/v1', getenv('TABLO_TEST_RUN_NONCE'), $root]) { throw new RuntimeException('Exact cell plan/source ownership refused.'); }
    ServerFixturePlan::runtime(SqlColumn::string($plan, 'runtime'), SqlColumn::string($plan, 'nonce'));
    server_cell_tool($plan);
    $pins = JsonRecord::decode(RequiredFile::bytes(file_get_contents($root . '/deploy/database-engines.json')));
    $services = JsonRecord::checked($plan['services'] ?? null);
    foreach (array_keys($services) as $engine) {
        server_cell_service_declaration(JsonRecord::checked($services[$engine] ?? null), JsonRecord::checked($pins[$engine] ?? null), (string) $engine, $plan);
    }
    $context = ['plan' => $plan, 'directory' => new TemporaryDirectory('tablo-server-cell-'), 'environment' => $environment];
    foreach (array_keys($services) as $engine) { server_cell_service_identity($context, (string) $engine); }
    return $context;
}

function server_cell_tool(array $plan): void
{
    $tool = JsonRecord::checked($plan['docker'] ?? null); $path = SqlColumn::string($tool, 'path');
    if (is_link($path) || !hash_equals(SqlColumn::string($tool, 'sha256'), RequiredFile::digest($path))) { throw new RuntimeException('Pinned Docker executable changed.'); }
    server_cell_context_declaration($plan, PHP_OS_FAMILY);
}

function server_cell_service_declaration(array $service, array $pin, string $engine, array $plan): void
{
    ServerFixturePlan::slots(SqlColumn::string($plan, 'nonce'), $engine);
    if (($service['reference'] ?? null) !== SqlColumn::string($pin, 'image')
        || preg_match('/\A[0-9a-f]{64}\z/D', SqlColumn::string($service, 'containerId')) !== 1
        || preg_match('/\Asha256:[0-9a-f]{64}\z/D', SqlColumn::string($service, 'imageId')) !== 1) {
        throw new RuntimeException('Exact saved service image/container identity refused.');
    }
    $expected = 'tablo-i39-' . substr(SqlColumn::string($plan, 'nonce'), offset: 0, length: 12) . '-' . $engine . '-data';
    if (SqlColumn::string($service, 'volume') !== $expected) { throw new RuntimeException('Exact owned server volume refused.'); }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_service(array $context, string $engine): array
{
    $services = JsonRecord::checked($context['plan']['services'] ?? null);
    return JsonRecord::checked($services[$engine] ?? null);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param list<string> $arguments
 * @return Subprocess::Result */
function server_cell_docker(array $context, array $arguments, float $timeout, #[\SensitiveParameter] string $input = ''): array
{
    $lease = server_cell_observer_begin($timeout, label: 'native/docker/' . server_cell_docker_operation($arguments));
    server_cell_tool($context['plan']); $environment = server_cell_docker_environment($context);
    $command = server_cell_docker_arguments($context, $arguments, PHP_OS_FAMILY);
    $result = Subprocess::run($command, $context['directory'], $environment,
        new CommandOptions(timeout: $timeout, input: $input, replacementEnvironment: $environment));
    $receipt = SubprocessReceipt::read($result['files']['receipt']);
    if ([$result['exit_code'], $receipt['closed'], $receipt['root_exit_observed']] !== [0, true, true]) {
        throw new RuntimeException('Owned Docker command failed or its exit remains unknown.');
    }
    server_cell_observer_finish($lease, server_cell_observer_closed($result, $receipt));
    return $result;
}

/** Fixed command families only; never include a generated argument, SQL or resource name. */
function server_cell_docker_operation(array $arguments): string
{
    /** @var mixed $command Only exact literal command families are retained. */
    $command = $arguments[0] ?? null;
    /** @var mixed $action No resource argument is converted or serialized. */
    $action = $arguments[1] ?? null;
    return match ($command) {
        'context' => $action === 'inspect' ? 'context-inspect' : 'unknown',
        'image' => $action === 'inspect' ? 'image-inspect' : 'unknown',
        'container' => $action === 'inspect' ? 'container-inspect' : 'unknown',
        'volume' => match ($action) { 'inspect' => 'volume-inspect', 'create' => 'volume-create', 'rm' => 'volume-rm', default => 'unknown' },
        'network' => match ($action) { 'inspect' => 'network-inspect', 'create' => 'network-create', 'rm' => 'network-rm', default => 'unknown' },
        'inspect', 'create', 'start', 'exec', 'cp', 'stop', 'rm', 'pull', 'save', 'load', 'info', 'version' => $command,
        default => 'unknown',
    };
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_service_identity(array $context, string $engine, ?float $deadline = null): array
{
    $service = server_cell_service($context, $engine);
    $result = server_cell_docker($context, ['inspect', SqlColumn::string($service, 'containerId')],
        timeout: $deadline === null ? 8 : server_cell_service_remaining($deadline, maximum: 8));
    $rows = JsonRecord::decode($result['stdout']);
    if (count($rows) !== 1) { throw new RuntimeException('Exact owned container inspection refused.'); }
    $record = JsonRecord::checked($rows[0] ?? null); $config = JsonRecord::checked($record['Config'] ?? null);
    $labels = JsonRecord::checked($config['Labels'] ?? null);
    if ([$record['Id'] ?? null, $record['Image'] ?? null, $config['Image'] ?? null, $labels['tablo.issue39.nonce'] ?? null]
        !== [$service['containerId'] ?? null, $service['imageId'] ?? null, $service['reference'] ?? null, $context['plan']['nonce'] ?? null]) {
        throw new RuntimeException('Saved container identity or nonce changed.');
    }
    server_cell_volume_identity($record, SqlColumn::string($service, 'volume'));
    server_cell_volume_label($context, SqlColumn::string($service, 'volume'), $deadline);
    return $record;
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_volume_label(array $context, string $name, ?float $deadline = null): void
{
    $result = server_cell_docker($context, ['volume', 'inspect', $name],
        timeout: $deadline === null ? 8 : server_cell_service_remaining($deadline, maximum: 8));
    $rows = JsonRecord::decode($result['stdout']);
    if (count($rows) !== 1) { throw new RuntimeException('Exact owned volume inspection refused.'); }
    $record = JsonRecord::checked($rows[0] ?? null); $labels = JsonRecord::checked($record['Labels'] ?? null);
    if ([$record['Name'] ?? null, $labels['tablo.issue39.nonce'] ?? null] !== [$name, $context['plan']['nonce'] ?? null]) {
        throw new RuntimeException('Exact owned persistent volume label changed.');
    }
}

function server_cell_volume_identity(array $record, string $name): void
{
    $mounts = JsonRecord::checked($record['Mounts'] ?? null); $matched = 0;
    foreach (array_keys($mounts) as $index) {
        $mount = JsonRecord::checked($mounts[$index] ?? null);
        if (($mount['Destination'] ?? null) !== '/var/lib/mysql') { continue; }
        if ([$mount['Type'] ?? null, $mount['Name'] ?? null] !== ['volume', $name]) { throw new RuntimeException('Server data-volume identity changed.'); }
        $matched++;
    }
    if ($matched !== 1) { throw new RuntimeException('Exact persistent server data mount absent.'); }
}

/** Root credentials remain in the mounted private defaults file; SQL travels only through owned stdin.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_admin(array $context, string $engine, #[\SensitiveParameter] string $sql, float $remaining): string
{
    if ($remaining <= 0 || $remaining > 30) { throw new RuntimeException('Finite fixture setup deadline refused.'); }
    $result = server_cell_docker($context, server_cell_client_arguments($context, $engine), $remaining, $sql);
    if ($result['stderr'] !== '') { throw new RuntimeException('Native private client warning/refusal.'); }
    return $result['stdout'];
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @return array<string, ServerFixtureCatalog> */
function server_cell_catalogs(array $context): array
{
    $catalogs = []; $services = JsonRecord::checked($context['plan']['services'] ?? null);
    foreach (array_keys($services) as $engine) {
        $engine = (string) $engine;
        $catalog = new ServerFixtureCatalog(SqlColumn::string($context['plan'], 'nonce'), $engine,
            static fn (#[\SensitiveParameter] string $sql, float $remaining): string => server_cell_admin($context, $engine, $sql, $remaining));
        $service = server_cell_service($context, $engine);
        $private = server_cell_private_input($context['plan'], SqlColumn::string($service, 'passwordFile'));
        $passwords = ['writer' => SqlColumn::string($private, 'writer'), 'reader' => SqlColumn::string($private, 'reader'),
            'migrator' => SqlColumn::string($private, 'migrator'), 'deniedDdl' => SqlColumn::string($private, 'deniedDdl'), 'metadata' => SqlColumn::string($private, 'metadata')];
        $catalog->bootstrap($passwords); unset($passwords, $private);
        $catalogs[$engine] = $catalog;
    }
    return $catalogs;
}

function server_cell_private_input(array $plan, string $path): array
{
    $root = SqlColumn::string($plan, 'runtime') . '/private/';
    if (!str_starts_with(str_replace('\\', replace: '/', subject: $path), $root) || str_contains($path, '..') || is_link($path)) {
        throw new RuntimeException('Private native cell input ownership refused.');
    }
    $bytes = RequiredFile::bytes(file_get_contents($path, length: 65_537));
    if (strlen($bytes) > 65_536) { throw new RuntimeException('Private native cell input exceeded its bound.'); }
    return JsonRecord::decode($bytes);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, ServerFixtureCatalog> $catalogs
 * @param array{engine: string, lane: string, index: int} $selection
 * @return array{environment: array<string, string>, closureDirectory: string, fixtures: list<ServerFixturePlan::Fixture>} */
function server_cell_prepare(array $context, array $catalogs, array $spec, array $selection): array
{
    $preparation = server_cell_observer_begin(30, label: 'fixture/prepare');
    $deadline = hrtime(true) / 1_000_000_000 + 30;
    $engine = $selection['engine']; $lane = $selection['lane']; $index = $selection['index'];
    $nonce = SqlColumn::string($context['plan'], 'nonce'); $identity = SqlColumn::string($spec, 'identity');
    $fixtures = ServerFixturePlan::fixtures($spec, $nonce, $engine, $lane);
    $base = SqlColumn::string($context['plan'], 'runtime') . '/cases/' . $lane . '-' . $index;
    server_cell_directory($base); $closure = $base . '/closure'; server_cell_directory($closure);
    foreach ($catalogs as $catalog) { $catalog->begin($identity, $fixtures, server_cell_remaining($deadline)); }
    $environment = server_cell_case_manifests($context, $fixtures, $base, $engine);
    $environment['TABLO_SESSION_EVIDENCE_DIRECTORY'] = $closure;
    $input = $base . '/initialize.json';
    server_cell_private_write($input, \Tablo\JsonEncoding::encode(['schema' => 'tablo/server-fixture-initialize/v1', 'nonce' => $nonce, 'fixtures' => $fixtures, 'engine' => $engine]));
    $result = Subprocess::run([PHP_BINARY, server_cell_script($context, relative: 'tools/server-cell.php'), '--initialize=' . $input], $context['directory'], $environment,
        new CommandOptions(timeout: server_cell_remaining($deadline), replacementEnvironment: $environment));
    if ([$result['exit_code'], $result['stderr']] !== [0, '']) { throw new RuntimeException('Native fixture preparation failed or its outcome is unknown.'); }
    server_cell_metadata_controls($catalogs, $fixtures, $base, $nonce);
    server_cell_observer_finish($preparation, server_cell_observer_closed($result, SubprocessReceipt::read($result['files']['receipt'])));
    if (array_key_exists('crash', $spec)) { server_cell_crash($context, $environment, $spec); }
    return ['environment' => $environment, 'closureDirectory' => $closure, 'fixtures' => $fixtures];
}

/** @param array<string, ServerFixtureCatalog> $catalogs
 * @param list<ServerFixturePlan::Fixture> $fixtures */
function server_cell_metadata_controls(array $catalogs, array $fixtures, string $base, string $nonce): void
{
    foreach ($catalogs as $catalog) {
        foreach ($catalog->metadataControls($fixtures) as $alias => $record) {
            $fixture = array_values(array_filter($fixtures, static fn (array $fixture): bool => $fixture['alias'] === $alias))[0] ?? throw new RuntimeException('Owned metadata fixture absent.');
            $path = $base . '/' . $fixture['engine'] . '/tablo-server-' . $nonce . '/private/' . $alias . '.metadata.json';
            server_cell_private_write($path, \Tablo\JsonEncoding::encode(['schema' => 'tablo/metadata-control/v1', 'nonce' => $nonce, 'engine' => $fixture['engine'], 'catalog' => $fixture['catalog']] + $record));
        }
    }
}

function server_cell_remaining(float $deadline): float
{
    $remaining = $deadline - hrtime(true) / 1_000_000_000;
    if ($remaining <= 0) { throw new RuntimeException('Finite native fixture setup deadline exceeded.'); }
    return min(30, $remaining);
}

function server_cell_directory(string $path): void
{
    if (file_exists($path) || is_link($path) || !mkdir($path, permissions: 0o700, recursive: true)) { throw new RuntimeException('Fresh native cell directory creation refused.'); }
}

function server_cell_private_write(string $path, #[\SensitiveParameter] string $bytes): void
{
    $handle = fopen($path, mode: 'xb');
    if (!is_resource($handle)) { throw new RuntimeException('Private native cell output already exists or is unavailable.'); }
    try {
        if (!chmod($path, permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) { throw new RuntimeException('Private native cell output failed.'); }
    } finally { fclose($handle); }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param list<ServerFixturePlan::Fixture> $fixtures
 * @return array<string, string> */
function server_cell_case_manifests(array $context, array $fixtures, string $base, string $selected): array
{
    $environment = array_replace($context['environment'], ['TABLO_TEST_DB_DRIVER' => $selected]);
    $services = JsonRecord::checked($context['plan']['services'] ?? null);
    foreach (array_keys($services) as $engine) {
        $engine = (string) $engine; $directory = $base . '/' . $engine . '/tablo-server-' . SqlColumn::string($context['plan'], 'nonce');
        server_cell_directory($directory . '/private'); $cases = [];
        foreach ($fixtures as $fixture) {
            if ($fixture['engine'] !== $engine) { continue; }
            $cases[$fixture['alias']] = $fixture['catalog']; server_cell_directory($directory . '/cases/' . $fixture['alias']);
        }
        $service = server_cell_service($context, $engine);
        $input = server_cell_private_input($context['plan'], SqlColumn::string($service, 'passwordFile'));
        $key = RequiredFile::bytes(file_get_contents(SqlColumn::string($context['plan'], 'key')));
        if (strlen($key) !== 32) { throw new RuntimeException('Shared native fixture raw key must be exactly32 bytes.'); }
        server_cell_private_write($directory . '/private/installation.bin', $key); unset($key);
        server_cell_private_write($directory . '/private/fixture-manifest.json', \Tablo\JsonEncoding::encode(['format' => 1,
            'nonce' => $context['plan']['nonce'] ?? null, 'engine' => $engine, 'cases' => $cases,
            'environment' => server_cell_fixture_environment($context['plan'], $service, $engine, $input)]));
        $environment[$engine === $selected ? 'TABLO_TEST_RUNTIME_ROOT' : 'TABLO_TEST_CROSS_RUNTIME_ROOT'] = $directory;
    }
    return $environment;
}

/** @return array<string, string> */
function server_cell_fixture_environment(array $plan, array $service, string $engine, #[\SensitiveParameter] array $passwords): array
{
    $accounts = ServerFixturePlan::accounts(SqlColumn::string($plan, 'nonce'), $engine);
    return ['TABLO_DB_HOST' => '127.0.0.1', 'TABLO_DB_PORT' => (string) SqlColumn::integer($service, 'port'), 'TABLO_DB_TLS' => 'verify',
        'TABLO_DB_TLS_CA' => SqlColumn::string($plan, 'ca'), 'TABLO_DB_USER' => $accounts['writer'], 'TABLO_DB_PASSWORD' => SqlColumn::string($passwords, 'writer'),
        'TABLO_DB_READ_USER' => $accounts['reader'], 'TABLO_DB_READ_PASSWORD' => SqlColumn::string($passwords, 'reader'),
        'TABLO_DB_MIGRATE_USER' => $accounts['migrator'], 'TABLO_DB_MIGRATE_PASSWORD' => SqlColumn::string($passwords, 'migrator'),
        'TABLO_DB_METADATA_PASSWORD' => SqlColumn::string($passwords, 'metadata'),
        'TABLO_TEST_DB_DENIED_DDL_USER' => $accounts['deniedDdl'], 'TABLO_TEST_DB_DENIED_DDL_PASSWORD' => SqlColumn::string($passwords, 'deniedDdl')];
}

/** This separate bounded setup process never executes the behavior of an initial=empty case. */
function server_cell_initialize(string $path): void
{
    $progress = ['engine' => null, 'stage' => 'bootstrap', 'point' => null, 'step' => null];
    try {
        $root = str_replace('\\', replace: '/', subject: dirname(__DIR__));
        require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root);
        require_once $root . '/vendor/autoload.php';
        require_once __DIR__ . '/ServerFixturePlan.php';
        $progress['stage'] = 'input';
        if (is_link($path) || basename($path) !== 'initialize.json') { throw new RuntimeException('Exact fixture setup input refused.'); }
        $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)));
        if ([$record['schema'] ?? null, $record['nonce'] ?? null] !== ['tablo/server-fixture-initialize/v1', getenv('TABLO_TEST_RUN_NONCE')]) { throw new RuntimeException('Fixture setup nonce refused.'); }
        $engine = SqlColumn::string($record, 'engine');
        $fixtures = JsonRecord::checked($record['fixtures'] ?? null);
        foreach (array_keys($fixtures) as $index) { server_cell_initialize_fixture(JsonRecord::checked($fixtures[$index] ?? null), $engine, $progress); }
        fwrite(STDOUT, data: "FIXTURE_READY\n");
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(server_cell_initialize_failure($error, $progress), JSON_THROW_ON_ERROR) . "\n");
        throw $error;
    }
}

/** @param array{engine: ?string, stage: string, point: ?string, step: ?int} $progress */
function server_cell_initialize_fixture(array $record, string $selected, array &$progress): void
{
    $engine = SqlColumn::string($record, 'engine'); $peer = $engine === $selected ? null : $engine;
    $progress = ['engine' => $engine, 'stage' => 'fixture', 'point' => null, 'step' => null];
    $fixture = new EngineFixture(SqlColumn::string($record, 'alias'), $peer); $db = null;
    try {
        $progress['stage'] = 'connection'; $db = $fixture->maintenance();
        $progress['stage'] = 'objects';
        if (\Tablo\ServerSchema::objects($db) !== []) { throw new RuntimeException('Initial native fixture object union is not empty.'); }
        $initial = SqlColumn::string($record, 'initial');
        if (!in_array($initial, ServerFixturePlan::INITIAL_STATES, strict: true)) { throw new RuntimeException('Reviewed fixture initial state required.'); }
        if ($initial !== 'empty') {
            $progress['stage'] = 'migration';
            $barrier = static function (string $point, int $step) use (&$progress): void { $progress['point'] = $point; $progress['step'] = $step; };
            (new \Tablo\ServerMigration($db, $fixture->vault, $barrier))->initialize(\Tablo\Tests\Support\EngineSchedule::receipt($fixture->case, $peer));
            $progress['stage'] = 'admission';
            \Tablo\ServerSchema::admit($db);
        }
    } finally { $db = null; $fixture->close(); }
}

/** Fixed fields only, including failures before the application autoloader is available.
 * @param array{engine: ?string, stage: string, point: ?string, step: ?int} $progress
 * @return array{schema: string, engine: ?string, stage: string, type: string, category: ?string, sqlState: ?string, nativeCode: ?int, nativeReason: string, source: ?string, line: ?int, point: ?string, step: ?int} */
function server_cell_initialize_failure(#[\SensitiveParameter] Throwable $error, array $progress): array
{
    $record = server_cell_availability_failure($error, $progress['engine'], stage: 'bootstrap');
    $record['schema'] = 'tablo/server-initialize-failure/v1';
    $record['stage'] = in_array($progress['stage'], ['bootstrap', 'input', 'fixture', 'connection', 'objects', 'migration', 'admission'], strict: true) ? $progress['stage'] : 'unknown';
    $record['point'] = in_array($progress['point'], ['before-ddl', 'after-ddl', 'after-journal', 'before-publication', 'after-publication'], strict: true) ? $progress['point'] : null;
    $record['step'] = $progress['step'] !== null && $progress['step'] >= 0 && $progress['step'] < 18 ? $progress['step'] : null;
    return $record;
}

/** Actual service termination is a separate explicit cross-lane lifecycle authority.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, string> $environment */
function server_cell_crash(array $context, array $environment, array $spec): void
{
    if (($context['plan']['allowServerCrash'] ?? null) !== true || [PHP_MAJOR_VERSION, PHP_MINOR_VERSION] !== [8, 5]) {
        throw new RuntimeException('Concrete owned server-crash lifecycle grant absent.');
    }
    $crash = JsonRecord::checked($spec['crash'] ?? null); $engine = SqlColumn::string($crash, 'engine');
    $boundary = SqlColumn::string($crash, 'boundary'); $fixtures = JsonRecord::checked($spec['fixtures'] ?? null);
    $fixture = JsonRecord::checked($fixtures[0] ?? null); $case = SqlColumn::string($fixture, 'alias');
    $expected = $boundary === 'after-ddl' ? 'server-crash-ddl' : 'server-crash-publication';
    if (count($fixtures) !== 1 || !in_array($boundary, ['after-ddl', 'before-publication'], strict: true)
        || [$case, $fixture['engine'] ?? null, $fixture['initial'] ?? null] !== [$expected, $engine, 'empty']) {
        throw new RuntimeException('Unreviewed server-crash case or durable boundary.');
    }
    $variable = $engine === 'mysql' ? 'TABLO_TEST_RUNTIME_ROOT' : 'TABLO_TEST_CROSS_RUNTIME_ROOT';
    $actorEnvironment = array_replace($environment, ['TABLO_TEST_DB_DRIVER' => $engine, 'TABLO_TEST_RUNTIME_ROOT' => SqlColumn::string($environment, $variable)]);
    $operation = $boundary === 'after-ddl' ? 'exit-after-ddl' : 'exit-before-publication';
    $child = new Subprocess([PHP_BINARY, server_cell_script($context, relative: 'tests/server-child.php'), $case, $operation], $context['directory'], $actorEnvironment,
        new CommandOptions(replacementEnvironment: $actorEnvironment));
    $primary = null;
    try { server_cell_crash_actor($context, $environment, ['engine' => $engine, 'boundary' => $boundary, 'case' => $case], $child); }
    catch (Throwable $error) { $primary = $error; $context['directory']->retainFailure($error); throw $error; }
    finally { $context['directory']->cleanup(static fn () => $child->close($primary)); }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, string> $environment
 * @param array{engine: string, boundary: string, case: string} $selection */
function server_cell_crash_actor(array $context, array $environment, array $selection, Subprocess $child): void
{
    $engine = $selection['engine'];
    $child->awaitOutput("ENGINE_MIGRATION_READY\n", timeout: 2); $child->sendBarrier("go\n");
    $child->awaitOutput("ENGINE_MIGRATION_EXIT_BOUNDARY\n", timeout: 6);
    $before = server_cell_crash_snapshot($environment, $selection, JsonRecord::checked($context['plan']['services'] ?? null));
    $service = server_cell_service($context, $engine);
    server_cell_service_identity($context, $engine);
    server_cell_docker($context, ['kill', '--signal=SIGKILL', SqlColumn::string($service, 'containerId')], timeout: 8);
    $stopped = server_cell_service_identity($context, $engine); $state = JsonRecord::checked($stopped['State'] ?? null);
    if ([$state['Running'] ?? null, $state['ExitCode'] ?? null] !== [false, 137]) { throw new RuntimeException('Actual owned SIGKILL exit137 was not observed.'); }
    $child->sendBarrier("exit\n"); $actor = $child->finish(timeout: 6);
    if ([$actor['exit_code'], $actor['stderr'], $actor['stdout']] !== [86, '', "ENGINE_MIGRATION_READY\nENGINE_MIGRATION_EXIT_BOUNDARY\n"]) {
        throw new RuntimeException('Server-crash migration actor closure refused.');
    }
    $actorReceipt = SubprocessReceipt::read($actor['files']['receipt']);
    if ([$actorReceipt['closed'], $actorReceipt['root_exit_observed']] !== [true, true]) { throw new RuntimeException('Server-crash direct actor closure unknown.'); }
    $restart = server_cell_restart($context, $environment, $selection);
    server_cell_crash_record($context, $environment, $selection, ['before' => $before, 'restart' => $restart, 'actor' => $actor]);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, string> $environment
 * @param array{engine: string, boundary: string, case: string} $selection */
function server_cell_crash_record(array $context, array $environment, array $selection, array $facts): void
{
    $engine = $selection['engine']; $case = $selection['case']; $service = server_cell_service($context, $engine);
    $before = JsonRecord::checked($facts['before'] ?? null); $actor = JsonRecord::checked($facts['actor'] ?? null);
    $files = JsonRecord::checked($actor['files'] ?? null);
    $directory = SqlColumn::string($environment, $engine === 'mysql' ? 'TABLO_TEST_RUNTIME_ROOT' : 'TABLO_TEST_CROSS_RUNTIME_ROOT');
    server_cell_private_write($directory . '/private/' . $case . '.server-crash.json', \Tablo\JsonEncoding::encode([
        'schema' => 'tablo/server-crash/v1', 'nonce' => $context['plan']['nonce'] ?? null, 'case' => $case, 'engine' => $engine,
        'catalog' => SqlColumn::string($before, 'catalog'), 'boundary' => $selection['boundary'], 'containerId' => $service['containerId'] ?? null,
        'imageId' => $service['imageId'] ?? null, 'imageReference' => $service['reference'] ?? null, 'volume' => $service['volume'] ?? null,
        'beforeSnapshotSha256' => SqlColumn::string($before, 'snapshot'), 'bootstrapReceiptSha256' => SqlColumn::string($before, 'receipt'),
        'kill' => ['signal' => 'SIGKILL', 'exitCode' => 137, 'observedStopped' => true], 'restart' => $facts['restart'] ?? null,
        'actor' => ['closed' => true, 'exitCode' => 86, 'boundaryObserved' => true, 'receiptSha256' => RequiredFile::digest(SqlColumn::string($files, 'receipt'))]]));
}

/** @param array<string, string> $environment
 * @param array{engine: string, boundary: string, case: string} $selection
 * @return array{catalog: string, snapshot: string, receipt: string} */
function server_cell_crash_snapshot(array $environment, array $selection, array $services): array
{
    $peer = $selection['engine'] === 'mysql' ? null : 'mariadb';
    $before = server_cell_with_environment($environment, static function () use ($selection, $peer): array {
        $fixture = new EngineFixture($selection['case'], $peer); $db = $fixture->maintenance();
        try {
            $tables = $selection['boundary'] === 'after-ddl' ? 4 : 18;
            if (count(\Tablo\ServerSchema::objects($db)) !== $tables
                || (new \Tablo\ServerMigration($db, $fixture->vault))->status() !== ['format' => 1, 'logical_version' => 0, 'phase' => 'initializing']) {
                throw new RuntimeException('Actual pre-crash durable boundary not observed.');
            }
            return ['catalog' => SqlColumn::string($fixture->environment(), 'TABLO_DB_NAME'),
                'snapshot' => \Tablo\Tests\ServerCross\EngineServerCrashTest::snapshotDigest($db),
                'receipt' => RequiredFile::digest(\Tablo\Tests\Support\EngineSchedule::receipt($selection['case'], $peer))];
        } finally { $db = null; $fixture->close(); }
    }, $services);
    return ['catalog' => SqlColumn::string($before, 'catalog'), 'snapshot' => SqlColumn::string($before, 'snapshot'), 'receipt' => SqlColumn::string($before, 'receipt')];
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, string> $environment
 * @param array{engine: string, boundary: string, case: string} $selection */
function server_cell_restart(array $context, array $environment, array $selection): array
{
    $deadline = hrtime(true) / 1_000_000_000 + 180;
    $engine = $selection['engine'];
    $service = server_cell_service($context, $engine);
    server_cell_docker($context, ['start', SqlColumn::string($service, 'containerId')], server_cell_service_remaining($deadline, maximum: 8));
    $directory = SqlColumn::string($environment, $engine === 'mysql' ? 'TABLO_TEST_RUNTIME_ROOT' : 'TABLO_TEST_CROSS_RUNTIME_ROOT');
    $input = $directory . '/private/' . $selection['case'] . '.restart-ready.json';
    server_cell_private_write($input, \Tablo\JsonEncoding::encode(['schema' => 'tablo/server-restart-readiness/v1',
        'nonce' => $context['plan']['nonce'] ?? null, 'engine' => $engine, 'case' => $selection['case']]));
    $ready = Subprocess::run([PHP_BINARY, server_cell_script($context, relative: 'tools/server-cell.php'), '--restart-ready=' . $input], $context['directory'], $environment,
        new CommandOptions(timeout: server_cell_service_remaining($deadline, maximum: 30), replacementEnvironment: $environment));
    if ([$ready['exit_code'], $ready['stdout'], $ready['stderr']] !== [0, "FRESH_TLS_PDO_READY\n", '']) {
        throw new RuntimeException('Fresh authenticated TLS PDO after real server restart unavailable.');
    }
    $running = server_cell_service_identity($context, $engine, $deadline); $state = JsonRecord::checked($running['State'] ?? null);
    server_cell_require_publication($running, SqlColumn::integer($service, 'port'));
    server_cell_service_remaining($deadline, maximum: 8); // Late success cannot replenish the joint180 budget.
    if (($state['Running'] ?? null) !== true) { throw new RuntimeException('Restarted owned service is not running.'); }
    return ['containerId' => $service['containerId'] ?? null, 'imageId' => $service['imageId'] ?? null,
        'volume' => $service['volume'] ?? null, 'observedRunning' => true, 'freshAuthenticatedTlsPdo' => true];
}

function server_cell_restart_ready(string $path): void
{
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__));
    require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root);
    require_once $root . '/vendor/autoload.php';
    if (is_link($path) || !str_ends_with($path, '.restart-ready.json')) { throw new RuntimeException('Exact owned restart-readiness input refused.'); }
    $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)));
    if ([$record['schema'] ?? null, $record['nonce'] ?? null] !== ['tablo/server-restart-readiness/v1', getenv('TABLO_TEST_RUN_NONCE')]) {
        throw new RuntimeException('Exact owned restart-readiness nonce refused.');
    }
    $engine = SqlColumn::string($record, 'engine'); $case = SqlColumn::string($record, 'case');
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true) || !in_array($case, ['server-crash-ddl', 'server-crash-publication'], strict: true)) {
        throw new RuntimeException('Exact restart-readiness fixture refused.');
    }
    $deadline = hrtime(true) / 1_000_000_000 + 29;
    do {
        if (server_cell_restart_pdo($engine, $case)) { fwrite(STDOUT, data: "FRESH_TLS_PDO_READY\n"); return; }
        usleep(100_000);
    } while (hrtime(true) / 1_000_000_000 < $deadline);
    throw new RuntimeException('Fresh authenticated TLS PDO after restart deadline exceeded.');
}

function server_cell_restart_pdo(string $engine, string $case): bool
{
    try {
        $fixture = new EngineFixture($case, $engine === 'mysql' ? null : 'mariadb'); $db = $fixture->maintenance();
        try {
            $ssl = \Tablo\ServerSchema::rows($db, "SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            return SqlColumn::string($ssl[0] ?? [], 'Value') !== '';
        } finally { $db = null; $fixture->close(); }
    } catch (\Tablo\DatabaseFailure $failure) {
        if ($failure->category !== 'unavailable') { throw $failure; }
        return false;
    }
}

/** Only authored test variables change, with declared services validated before any mutation.
 * @param array<string, string> $environment
 * @param \Closure(): array $operation */
function server_cell_with_environment(array $environment, \Closure $operation, array $services): array
{
    $values = server_cell_environment_values($environment, $services); $before = [];
    foreach (array_keys($values) as $name) { $before[$name] = getenv($name); }
    try {
        foreach ($values as $name => $value) {
            if (!putenv($value === null ? $name : $name . '=' . $value)) { throw new RuntimeException('Scoped native environment selection failed.'); }
        }
        return $operation();
    } finally { foreach ($before as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); } }
}

/** @param array<string, string> $environment
 * @return array<string, ?string> */
function server_cell_environment_values(array $environment, array $services): array
{
    $values = [];
    foreach (['TABLO_TEST_RUN_NONCE', 'TABLO_TEST_DB_DRIVER', 'TABLO_TEST_RUNTIME_ROOT'] as $name) {
        $value = SqlColumn::string($environment, $name);
        if ($value === '') { throw new RuntimeException('Required native environment value is empty.'); }
        $values[$name] = $value;
    }
    $selected = SqlColumn::string($environment, 'TABLO_TEST_DB_DRIVER'); $engines = array_keys($services); sort($engines, SORT_STRING);
    if (!in_array($selected, ['mysql', 'mariadb'], strict: true)) { throw new RuntimeException('Declared native environment engine refused.'); }
    if ($engines === [$selected]) {
        if (array_key_exists('TABLO_TEST_CROSS_RUNTIME_ROOT', $environment)) { throw new RuntimeException('Undeclared cross runtime refused.'); }
        return $values + ['TABLO_TEST_CROSS_RUNTIME_ROOT' => null];
    }
    if ($engines !== ['mariadb', 'mysql']) { throw new RuntimeException('Declared native service union refused.'); }
    $cross = SqlColumn::string($environment, 'TABLO_TEST_CROSS_RUNTIME_ROOT');
    if ($cross === '') { throw new RuntimeException('Declared cross runtime is empty.'); }
    return $values + ['TABLO_TEST_CROSS_RUNTIME_ROOT' => $cross];
}

/** Source loading has no engine, process or storage side effects. */
/** @param list<string> $arguments */
function server_cell_entry(array $arguments): int
{
    $argument = $arguments[0] ?? '';
    if (str_starts_with($argument, '--plan=')) { server_cell_plan_entry($arguments); return 0; }
    if (str_starts_with($argument, '--execute=')) { server_cell_execute_entry($arguments); return 0; }
    if (str_starts_with($argument, '--continue=')) { server_cell_continue_entry($arguments); return 0; }
    if ($arguments === ['--capability']) { server_cell_capability(); return 0; }
    if (count($arguments) !== 1) { throw new RuntimeException('Explicit server cell operation required.'); }
    if (str_starts_with($argument, '--initialize=')) { server_cell_initialize(substr($argument, offset: strlen('--initialize='))); return 0; }
    if (str_starts_with($argument, '--restart-ready=')) { server_cell_restart_ready(substr($argument, offset: strlen('--restart-ready='))); return 0; }
    if (str_starts_with($argument, '--availability=')) { server_cell_availability_child(substr($argument, offset: strlen('--availability='))); return 0; }
    throw new RuntimeException('Explicit server cell operation required.');
}

/** Produces a finite file/resource declaration for review; it performs no daemon or native test action.
 * @param list<string> $arguments */
function server_cell_plan_entry(array $arguments): void
{
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__));
    $checkpoint = 'DEPENDENCY_GUARD';
    try {
        require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root);
        $checkpoint = 'LOAD';
        foreach (['JsonRecord', 'JsonEncoding', 'SqlColumn', 'DatabaseInteger', 'DatabaseFailure'] as $name) { require_once $root . '/app/' . $name . '.php'; }
        require_once __DIR__ . '/ServerFixturePlan.php';
        $checkpoint = 'OPTIONS'; $options = server_cell_plan_options($arguments);
        $checkpoint = 'PINS'; $plan = ServerFixturePlan::cell($root, $options);
        $checkpoint = 'PLAN_ENCODE'; $bytes = \Tablo\JsonEncoding::encode($plan, JSON_PRETTY_PRINT);
        $checkpoint = 'PLAN_WRITE'; server_cell_private_write($options['output'], $bytes);
        $checkpoint = 'PLAN_REPORT';
        fwrite(STDOUT, json_encode(['status' => 'declared', 'plan' => $options['output'], 'sha256' => RequiredFile::digest($options['output']),
            'nonce' => $plan['nonce'] ?? null, 'runtime' => $plan['runtime'] ?? null, 'executed' => false], JSON_THROW_ON_ERROR) . "\n");
    } catch (Throwable $error) {
        try { fwrite(STDERR, 'TABLO_DECLARATION ' . json_encode(server_cell_declaration_failure($error, $checkpoint), JSON_THROW_ON_ERROR) . "\n"); }
        catch (Throwable $discarded) { unset($discarded); }
        throw $error;
    }
}

/** Pure emergency projection, independent of the application and dependency autoloaders.
 * @return array{schema: string, status: string, stage: string, type: string, source: ?string, line: ?int, executed: bool} */
function server_cell_declaration_failure(#[\SensitiveParameter] Throwable $error, string $checkpoint): array
{
    $type = match (true) { $error instanceof JsonException => 'json', $error instanceof RuntimeException => 'runtime', $error instanceof Error => 'error', default => 'other' };
    return ['schema' => 'tablo/server-declaration-diagnostic/v1', 'status' => 'failed', 'stage' => server_cell_declaration_stage($error->getTrace(), $checkpoint),
        'type' => $type] + server_cell_declaration_origin($error->getFile(), $error->getLine()) + ['executed' => false];
}

function server_cell_declaration_stage(#[\SensitiveParameter] array $frames, string $checkpoint): string
{
    $stages = ['server_fixture_plan_bootstrap_ini' => 'BOOTSTRAP_INI', 'server_fixture_plan_observer' => 'OBSERVER',
        'server_fixture_plan_php_modules' => 'BOOTSTRAP_MODULES', 'server_fixture_plan_php_module_decode' => 'BOOTSTRAP_MODULES', 'server_fixture_plan_php_module_record' => 'BOOTSTRAP_MODULES',
        'server_fixture_plan_php_module_pins' => 'BOOTSTRAP_MODULES',
        'server_fixture_plan_docker' => 'DOCKER_PROFILE', 'server_fixture_plan_tool' => 'TOOL_INPUT',
        'server_fixture_plan_linux_inputs' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_file' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_shell' => 'LINUX_QUALIFICATION', 'server_fixture_plan_nonce' => 'OPTIONS',
        'server_fixture_plan_linux_provenance' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_receipt' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_qualification' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_runtimes' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_runtime_binding' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_references' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_reference' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_path' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_inputs' => 'TOOL_INPUT', 'server_fixture_plan_source_manifest' => 'SOURCE_MANIFEST',
        'server_fixture_plan_source_file' => 'SOURCE_MANIFEST', 'server_fixture_plan_vendor' => 'VENDOR_REFERENCES',
        'server_fixture_plan_vendor_files' => 'VENDOR_FILES', 'server_fixture_plan_mago_files' => 'MAGO_FILES',
        'server_cell_plan_options' => 'OPTIONS', 'server_fixture_plan_service' => 'PINS', 'verify_worker_dependencies' => 'DEPENDENCY_GUARD'];
    /** @var mixed $frame Native trace records are checked before their function-name projection. */
    foreach (array_slice($frames, offset: 0, length: 32) as $frame) {
        if (!is_array($frame)) { continue; }
        /** @var mixed $function Only a fixed recognized function name can influence a stage. */
        $function = $frame['function'] ?? null;
        if (is_string($function) && !array_key_exists('class', $frame) && array_key_exists($function, $stages)) { return $stages[$function]; }
    }
    return in_array($checkpoint, ['DEPENDENCY_GUARD', 'LOAD', 'OPTIONS', 'PINS', 'PLAN_ENCODE', 'PLAN_WRITE', 'PLAN_REPORT'], strict: true) ? $checkpoint : 'UNKNOWN';
}

/** @return array{source: ?string, line: ?int} */
function server_cell_declaration_origin(#[\SensitiveParameter] string $origin, int $line): array
{
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__)) . '/';
    $origin = str_replace('\\', replace: '/', subject: $origin);
    foreach (server_cell_declaration_sources() as $source) {
        if ($origin === $root . $source) { return ['source' => $source, 'line' => $line > 0 && $line <= 1_000_000 ? $line : null]; }
    }
    return ['source' => null, 'line' => null];
}

/** @return list<string> */
function server_cell_declaration_sources(): array
{
    return ['tools/server-cell.php', 'tools/ServerFixturePlan.php', 'tools/reviewed-dependencies.php',
        'tools/ReviewedDependencyManifest.php', 'tools/ReviewedDependencyCatalog.php', 'tools/ReviewedDependencyEntry.php',
        'tools/ReviewedDependencyCoverage.php', 'tools/MagoSource.php', 'tools/MagoPin.php', 'app/RequiredFile.php',
        'app/JsonRecord.php', 'app/JsonEncoding.php', 'app/SqlColumn.php', 'app/DatabaseInteger.php', 'app/DatabaseFailure.php',
        'tests/Server/EngineVerificationTest.php'];
}

/** Only this validated projection may leave the private declaration captures. */
function server_cell_declaration_record(#[\SensitiveParameter] string $capture): ?array
{
    if (strlen($capture) > 4096) { return null; }
    $records = [];
    foreach (explode("\n", $capture) as $line) {
        if (!str_starts_with($line, 'TABLO_DECLARATION ')) { continue; }
        $records[] = substr(rtrim($line, characters: "\r"), offset: strlen('TABLO_DECLARATION '));
    }
    if (count($records) !== 1) { return null; }
    try {
        /** @var mixed $record Untrusted capture JSON must pass the exact shape and value checks. */
        $record = json_decode($records[0], associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
    }
    catch (JsonException $discarded) { unset($discarded); return null; }
    if (!is_array($record) || json_encode($record, JSON_THROW_ON_ERROR) !== $records[0] || array_keys($record) !== ['schema', 'status', 'stage', 'type', 'source', 'line', 'executed']
        || [$record['schema'] ?? null, $record['status'] ?? null, $record['executed'] ?? null] !== ['tablo/server-declaration-diagnostic/v1', 'failed', false]) { return null; }
    return server_cell_declaration_fields($record) ? $record : null;
}

function server_cell_declaration_fields(array $record): bool
{
    $stages = ['DEPENDENCY_GUARD', 'LOAD', 'OPTIONS', 'PINS', 'PLAN_ENCODE', 'PLAN_WRITE', 'PLAN_REPORT', 'BOOTSTRAP_INI', 'OBSERVER',
        'DOCKER_PROFILE', 'TOOL_INPUT', 'SOURCE_MANIFEST', 'VENDOR_REFERENCES', 'VENDOR_FILES', 'MAGO_FILES', 'LINUX_QUALIFICATION', 'BOOTSTRAP_MODULES', 'UNKNOWN'];
    if (!in_array($record['stage'] ?? null, $stages, strict: true) || !in_array($record['type'] ?? null, ['json', 'runtime', 'error', 'other'], strict: true)) { return false; }
    if (($record['source'] ?? null) === null) { return ($record['line'] ?? null) === null; }
    return in_array($record['source'], server_cell_declaration_sources(), strict: true) && is_int($record['line'] ?? null) && $record['line'] > 0 && $record['line'] <= 1_000_000;
}

/** @param list<string> $arguments
 * @return array{engine: string, cross: bool, pull: bool, output: string, diagnostic: bool} */
function server_cell_plan_options(array $arguments): array
{
    $values = []; $cross = false; $pull = false; $diagnostic = false;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--plan=')) { server_cell_plan_value($values, name: 'output', value: substr($argument, offset: strlen('--plan='))); continue; }
        if (str_starts_with($argument, '--engine=')) { server_cell_plan_value($values, name: 'engine', value: substr($argument, offset: strlen('--engine='))); continue; }
        if ($argument === '--cross' && !$cross) { $cross = true; continue; }
        if ($argument === '--pull' && !$pull) { $pull = true; continue; }
        if ($argument === '--declaration-diagnostic' && !$diagnostic) { $diagnostic = true; continue; }
        throw new RuntimeException('Unreviewed or repeated cell plan argument.');
    }
    $engine = SqlColumn::string($values, 'engine'); $output = SqlColumn::string($values, 'output');
    if (!in_array($engine, ['mysql', 'mariadb'], strict: true) || $output === '') { throw new RuntimeException('Required explicit engine/output absent.'); }
    if ($diagnostic && ($cross || $pull)) { throw new RuntimeException('Diagnostic-only declaration cannot request runtime controls.'); }
    return ['engine' => $engine, 'cross' => $cross, 'pull' => $pull, 'output' => $output, 'diagnostic' => $diagnostic];
}

/** @param array<string, string> $values */
function server_cell_plan_value(array &$values, string $name, string $value): void
{
    if (array_key_exists($name, $values) || $value === '') { throw new RuntimeException('Required cell plan argument repeated or empty.'); }
    $values[$name] = $value;
}

/** Execution is a separate explicit operation on the exact previously declared bytes.
 * @param list<string> $arguments */
function server_cell_execute_entry(array $arguments): void
{
    $checkpoint = 'ARGUMENTS'; $ready = false; $context = null; $capabilityFailure = null;
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__)); $roots = [$root];
    try {
    if (count($arguments) !== 2 || !str_starts_with($arguments[1] ?? '', '--sha256=')) { throw new RuntimeException('Exact frozen cell digest required.'); }
    $path = substr($arguments[0], offset: strlen('--execute=')); $digest = substr($arguments[1] ?? '', offset: strlen('--sha256='));
    $checkpoint = 'DEPENDENCY_GUARD';
    require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root);
    $checkpoint = 'LOAD';
    foreach (['JsonRecord', 'JsonEncoding', 'SqlColumn', 'DatabaseInteger', 'DatabaseFailure'] as $name) { require_once $root . '/app/' . $name . '.php'; }
    $checkpoint = 'PLAN_BINDING';
    if (is_link($path) || !hash_equals($digest, RequiredFile::digest($path))) { throw new RuntimeException('Frozen execution declaration changed.'); }
    $plan = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)), 64);
    if ([$plan['schema'] ?? null, $plan['sourceInput'] ?? null] !== ['tablo/server-cell-start/v1', $root]) { throw new RuntimeException('Cell declaration source identity refused.'); }
    $roots = server_cell_prepare_roots($root, $path, $plan);
    require_once __DIR__ . '/ServerFixturePlan.php';
    $checkpoint = 'OBSERVER_BINDING';
    server_cell_observer_require($plan);
    server_cell_observer_phase('prepare');
    $ready = true; $checkpoint = 'MATERIALIZE';
    server_cell_materialize($plan);
    // The inherited installation environment is never loaded; only the fresh copy has an empty .env.
    $checkpoint = 'AUTOLOAD';
    require_once SqlColumn::string($plan, 'source') . '/vendor/autoload.php';
    $checkpoint = 'ENVIRONMENT';
    $environment = server_cell_start_environment($plan);
    if (!putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . SqlColumn::string($environment, 'TABLO_SESSION_EVIDENCE_DIRECTORY'))) {
        throw new RuntimeException('Cannot select the exact owned server-cell receipt sink.');
    }
    $checkpoint = 'CONTROLLER_DIRECTORY';
    $context = ['plan' => $plan, 'directory' => new TemporaryDirectory('tablo-cell-controller-', SqlColumn::string($plan, 'runtime') . '/tmp'), 'environment' => $environment];
    $checkpoint = 'EXECUTE'; server_cell_execute($context, $capabilityFailure);
    } catch (Throwable $error) {
        server_cell_prepare_rethrow($error, ['checkpoint' => $checkpoint, 'roots' => $roots, 'capability' => $capabilityFailure], $ready,
            $context === null ? null : static fn () => $context['directory']->retainFailure($error));
    }
}

/** Only lexical, frozen repository roots are eligible; no origin is probed.
 * @return list<string> */
function server_cell_prepare_roots(string $root, string $path, array $plan): array
{
    /** @var mixed $nonce Only a validated literal nonce may name a copied source root. */
    $nonce = $plan['nonce'] ?? null; $parent = str_replace('\\', replace: '/', subject: dirname($path));
    $copy = is_string($nonce) ? $parent . '/tablo-server-' . $nonce . '/source' : '';
    if (($plan['sourceInput'] ?? null) === $root && is_string($nonce) && preg_match('/\A[0-9a-f]{32}\z/D', $nonce) === 1
        && preg_match('~\A(?:/[A-Za-z0-9_. /-]+|[A-Za-z]:/[A-Za-z0-9_. /-]+)\z~D', $parent) === 1
        && !str_contains($parent, '//') && preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D', $parent) !== 1
        && ($plan['runtime'] ?? null) === $parent . '/tablo-server-' . $nonce && ($plan['source'] ?? null) === $copy) { return [$root, $copy]; }
    return [$root];
}

/** @return list<string> */
function server_cell_prepare_sources(): array
{
    return ['tools/server-cell.php', 'tools/ServerFixturePlan.php', 'tools/ServerFixtureCatalog.php', 'tools/reviewed-dependencies.php',
        'tools/ReviewedDependencyManifest.php', 'tools/ReviewedDependencyCatalog.php', 'tools/ReviewedDependencyEntry.php',
        'tools/ReviewedDependencyCoverage.php', 'tools/MagoSource.php', 'tools/MagoPin.php', 'app/RequiredFile.php',
        'app/JsonRecord.php', 'app/JsonEncoding.php', 'app/SqlColumn.php', 'app/DatabaseInteger.php', 'app/DatabaseFailure.php',
        'app/bootstrap.php', 'tests/Support/TemporaryDirectory.php', 'tests/Support/Subprocess.php', 'tests/Support/SubprocessReceipt.php',
        'tests/Server/EngineVerificationTest.php'];
}

/** Pure first-failure projection: no message, trace, private bytes or origin metadata.
 * @param list<string> $roots
 * @return array{schema: 'tablo/server-prepare-failure/v5', status: 'failed', phase: 'prepare', rootCause: 'UNKNOWN', issueAccepted: false, stage: string, errorClass: string, source: ?string, line: ?int, capability: ?array} */
function server_cell_prepare_failure(#[\SensitiveParameter] Throwable $error, string $checkpoint, array $roots, ?array $capability = null): array
{
    $stages = ['ARGUMENTS', 'DEPENDENCY_GUARD', 'LOAD', 'PLAN_BINDING', 'OBSERVER_BINDING', 'MATERIALIZE', 'AUTOLOAD', 'ENVIRONMENT', 'CONTROLLER_DIRECTORY', 'EXECUTE'];
    $classes = ['RuntimeException', 'LogicException', 'InvalidArgumentException', 'JsonException', 'Error', 'TypeError', 'ValueError', 'ParseError', 'ErrorException'];
    $class = get_class($error); $source = null; $line = null; $origin = str_replace('\\', replace: '/', subject: $error->getFile());
    if ($error->getLine() > 0 && $error->getLine() <= 1_000_000) {
        foreach ($roots as $root) {
            foreach (server_cell_prepare_sources() as $candidate) {
                if ($origin !== $root . '/' . $candidate) { continue; }
                $source = $candidate; $line = $error->getLine();
            }
        }
    }
    return ['schema' => 'tablo/server-prepare-failure/v5', 'status' => 'failed', 'phase' => 'prepare', 'rootCause' => 'UNKNOWN', 'issueAccepted' => false,
        'stage' => in_array($checkpoint, $stages, strict: true) ? $checkpoint : 'UNKNOWN',
        'errorClass' => in_array($class, $classes, strict: true) ? $class : 'UNKNOWN', 'source' => $source, 'line' => $line,
        'capability' => $capability === null || ($capability['schema'] ?? null) !== 'tablo/php-capability-observation/v4' ? null : server_cell_capability_projection($capability)];
}

/** Earlier failures stay MISSING: obtaining helpers or observer authority here is forbidden. */
// @mago-expect lint:no-boolean-flag-parameter
function server_cell_prepare_report(array $record, bool $ready): void
{
    if (!$ready) { return; }
    foreach ([RequiredFile::class, JsonRecord::class, \Tablo\JsonEncoding::class, SqlColumn::class] as $helper) {
        if (!class_exists($helper, autoload: false)) { return; }
    }
    if (strlen(\Tablo\JsonEncoding::encode($record)) > 2_048) { return; }
    $reply = server_cell_observer_request('prepare-failure', ['diagnostic' => $record]);
    if (array_keys($reply) !== ['sequence', 'recorded'] || ($reply['recorded'] ?? null) !== true) { throw new RuntimeException('Prepare diagnostic acknowledgement refused.'); }
}

/** @param array{checkpoint: string, roots: list<string>, capability?: ?array} $failure */
function server_cell_prepare_rethrow(#[\SensitiveParameter] Throwable $error, array $failure, bool $ready, ?Closure $retain): never
{
    try { $record = server_cell_prepare_failure($error, $failure['checkpoint'], $failure['roots'], $failure['capability'] ?? null); server_cell_prepare_report($record, $ready); }
    catch (Throwable $discarded) { unset($discarded); }
    try { if ($retain !== null) { $retain(); } } catch (Throwable $discarded) { unset($discarded); }
    throw $error;
}

/** A distinct positively observed controller continues only the genuine saved preparation window.
 * @param list<string> $arguments */
function server_cell_continue_entry(array $arguments): void
{
    if (count($arguments) !== 2 || !str_starts_with($arguments[1] ?? '', '--sha256=')) { throw new RuntimeException('Exact continued cell digest required.'); }
    $path = substr($arguments[0], offset: strlen('--continue=')); $digest = substr($arguments[1] ?? '', offset: strlen('--sha256='));
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__));
    require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root);
    require_once $root . '/vendor/autoload.php';
    require_once __DIR__ . '/ServerFixturePlan.php'; require_once __DIR__ . '/ServerFixtureCatalog.php';
    if (is_link($path) || !hash_equals($digest, RequiredFile::digest($path))) { throw new RuntimeException('Continued cell provenance changed.'); }
    $plan = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)), 64);
    if ($path !== dirname($root) . '/evidence/started-cell.json' || ($plan['sourceState'] ?? null) !== 'STARTED_PENDING_NATIVE_VERIFICATION') {
        throw new RuntimeException('Actual saved preparation window required.');
    }
    server_cell_observer_require($plan); server_cell_observer_phase('engine');
    $context = null;
    try {
        $environment = server_cell_start_environment($plan) + ['TABLO_TEST_CELL_PLAN' => $path, 'TABLO_TEST_CELL_PLAN_SHA256' => $digest];
        $context = server_cell_context($root, $environment);
        server_cell_verify_and_close($context);
    } catch (Throwable $error) {
        server_cell_report_engine_failure($error, $root);
        try { if ($context !== null) { $context['directory']->retainFailure($error); } } catch (Throwable $discarded) { unset($discarded); }
        throw $error;
    }
}

/** Bounded source facts only; this record does not establish a host or product cause.
 * @return array{schema: string, status: string, phase: string, rootCause: string, issueAccepted: bool, engine: ?string, stage: string,
 * type: string, category: ?string, sqlState: ?string, nativeCode: ?int, nativeReason: string, source: ?string, line: ?int} */
function server_cell_engine_failure(#[\SensitiveParameter] Throwable $error): array
{
    return ['schema' => 'tablo/server-engine-failure/v1', 'status' => 'failed', 'phase' => 'engine', 'rootCause' => 'UNKNOWN', 'issueAccepted' => false]
        + server_cell_availability_failure($error, engine: null, stage: 'unknown');
}

/** Called only after frozen continuation/observer admission; secondary reporting failures never replace the primary. */
function server_cell_report_engine_failure(#[\SensitiveParameter] Throwable $error, string $root): void
{
    try {
        $runtime = dirname($root);
        if (basename($root) !== 'source' || preg_match('/\Atablo-server-[0-9a-f]{32}\z/D', basename($runtime)) !== 1) { return; }
        $bytes = json_encode(server_cell_engine_failure($error), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($bytes) > 1_024) { return; }
        server_cell_private_write(dirname($runtime) . '/safe-engine-failure.json', $bytes);
    } catch (Throwable $discarded) { unset($discarded); }
}

/** No installation directories are scanned or copied; every input is in the frozen finite inventory. */
function server_cell_materialize(array $plan): void
{
    $runtime = SqlColumn::string($plan, 'runtime'); $nonce = SqlColumn::string($plan, 'nonce');
    server_cell_ini_scan_directory($runtime); // Refuse list-valued paths before creating any runtime directory.
    if (basename($runtime) !== 'tablo-server-' . $nonce || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1) { throw new RuntimeException('Fresh cell root refused.'); }
    server_cell_directory($runtime);
    foreach (['private', 'private/tls', 'private/docker-config', 'private/php-ini-scan', 'tmp', 'sessions', 'composer-home', 'composer-cache', 'evidence', 'evidence/closure', 'source'] as $directory) { server_cell_directory($runtime . '/' . $directory); }
    $root = SqlColumn::string($plan, 'sourceInput'); $target = SqlColumn::string($plan, 'source');
    $manifest = JsonRecord::checked($plan['sourceManifest'] ?? null);
    $bytes = RequiredFile::bytes(file_get_contents(SqlColumn::string($manifest, 'path')));
    if (!hash_equals(SqlColumn::string($manifest, 'sha256'), hash('sha256', $bytes))) { throw new RuntimeException('Declared source inventory changed.'); }
    $records = JsonRecord::decode($bytes, 64);
    server_cell_copy_inventory($root, $target, JsonRecord::checked($records['files'] ?? null));
    server_cell_copy_inventory($root . '/vendor', $target . '/vendor', JsonRecord::checked($plan['vendorFiles'] ?? null));
    server_cell_copy_inventory($root, $target, JsonRecord::checked($plan['magoFiles'] ?? null));
    server_cell_private_write($target . '/.env', bytes: '');
    server_cell_private_write(SqlColumn::string($plan, 'key'), random_bytes(32));
    server_cell_tls($plan); server_cell_private_ini($plan);
    $services = JsonRecord::checked($plan['services'] ?? null);
    foreach (array_keys($services) as $engine) { server_cell_secrets($plan, (string) $engine); }
}

function server_cell_copy_inventory(string $source, string $target, array $files): void
{
    foreach (array_keys($files) as $index) {
        $record = JsonRecord::checked($files[$index] ?? null); $name = SqlColumn::string($record, 'path');
        if ($name === '' || str_contains($name, '..') || str_contains($name, ':') || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '/')) {
            throw new RuntimeException('Finite copy inventory path refused.');
        }
        $cursor = $source;
        foreach (explode('/', $name) as $component) { $cursor .= '/' . $component; if (is_link($cursor)) { throw new RuntimeException('Linked copy input refused.'); } }
        $bytes = RequiredFile::bytes(file_get_contents($source . '/' . $name));
        if (!hash_equals(SqlColumn::string($record, 'sha256'), hash('sha256', $bytes))) { throw new RuntimeException('Frozen copy input changed.'); }
        $output = $target . '/' . $name; $directory = dirname($output);
        if (!is_dir($directory)) { server_cell_directory($directory); }
        server_cell_private_write($output, $bytes);
        if (is_executable($source . '/' . $name) && !chmod($output, permissions: 0o700)) { throw new RuntimeException('Declared native executable copy permissions failed.'); }
    }
}

/** Linux uses only the frozen ordinary-module selection; a child must attest the new ini. */
function server_cell_private_ini(array $plan): void
{
    $runtime = SqlColumn::string($plan, 'runtime');
    $ini = PHP_OS_FAMILY === 'Linux' ? server_cell_private_ini_linux($plan) : server_cell_private_ini_legacy();
    $ini .= "display_errors=stderr\nlog_errors=0\nzend.exception_ignore_args=1\nerror_reporting=E_ALL\nmemory_limit=256M\nopcache.enable_cli=0\n";
    foreach (['sys_temp_dir' => $runtime . '/tmp', 'session.save_path' => $runtime . '/sessions', 'openssl.cafile' => SqlColumn::string($plan, 'ca')] as $key => $value) {
        if (str_contains($value, '"') || str_contains($value, "\n")) { throw new RuntimeException('Private ini value refused.'); }
        $ini .= $key . '="' . $value . '"' . "\n";
    }
    server_cell_private_write(SqlColumn::string($plan, 'ini'), $ini);
}

/** Recheck only selected ordinary module pins; never discover filenames or loader kinds. */
function server_cell_private_ini_linux(array $plan): string
{
    $observer = JsonRecord::checked($plan['observer'] ?? null);
    $pins = server_fixture_plan_php_module_pins($observer['bootstrapExtensionFiles'] ?? null);
    // Validate the entire list before inspecting even its first selected path.
    foreach ($pins as $pin) {
        if (preg_match('#\A/[a-zA-Z0-9_./+-]+\z#D', $pin['path']) !== 1) { throw new RuntimeException('Literal ordinary PHP module path required.'); }
    }
    $ini = '';
    foreach ($pins as $pin) {
        server_fixture_plan_linux_regular($pin['path']);
        if (!hash_equals($pin['sha256'], RequiredFile::digest($pin['path']))) { throw new RuntimeException('Selected PHP extension file changed.'); }
        $ini .= 'extension="' . $pin['path'] . '"' . "\n";
    }
    return $ini;
}

/** Preserve the existing non-Linux registry behavior and OPcache exception. */
function server_cell_private_ini_legacy(): string
{
    $extensionDirectory = ini_get('extension_dir');
    if (!is_string($extensionDirectory) || !is_dir($extensionDirectory)) { throw new RuntimeException('Actual PHP extension directory unavailable.'); }
    $ini = 'extension_dir="' . $extensionDirectory . '"' . "\n";
    foreach (get_loaded_extensions() as $extension) {
        $name = strtolower($extension); $file = $extensionDirectory . '/' . (PHP_OS_FAMILY === 'Windows' ? 'php_' . $name . '.dll' : $name . '.so');
        if ($name === 'zend opcache' || !is_file($file)) { continue; }
        $ini .= 'extension="' . $file . '"' . "\n";
    }
    return $ini;
}

function server_cell_capability(): void
{
    $category = 'UNKNOWN'; $missing = null;
    try {
    foreach (['curl', 'openssl', 'pdo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'dom', 'xml', 'xmlwriter'] as $extension) {
        if (extension_loaded($extension)) { continue; }
        $category = 'MISSING_EXTENSION'; $missing = $extension; throw new RuntimeException('Required actual private PHP extension absent.');
    }
    $runtime = getenv('TABLO_TEST_SOURCE_ROOT'); $ini = getenv('PHPRC');
    $loaded = php_ini_loaded_file();
    if (!is_string($runtime) || !is_string($ini) || !is_string($loaded)) {
        $category = match (true) { !is_string($runtime) => 'MISSING_SOURCE', !is_string($ini) => 'MISSING_INI', default => 'MISSING_LOADED_INI' };
        throw new RuntimeException('Actual private PHP configuration mismatch.');
    }
    if (str_replace('\\', replace: '/', subject: $loaded) !== $ini) { $category = 'INI_MISMATCH'; throw new RuntimeException('Actual private PHP configuration mismatch.'); }
    if (php_ini_scanned_files() !== false) { $category = 'INI_MISMATCH'; throw new RuntimeException('Additional private PHP configuration refused.'); }
    if (str_replace('\\', replace: '/', subject: sys_get_temp_dir()) !== dirname($runtime) . '/tmp') { $category = 'TEMP_MISMATCH'; throw new RuntimeException('Actual private PHP configuration mismatch.'); }
    if (fwrite(STDOUT, data: "PRIVATE_PHP_READY\n") !== strlen("PRIVATE_PHP_READY\n")) { $category = 'READY_WRITE_FAILED'; throw new RuntimeException('Private PHP readiness output incomplete.'); }
    } catch (Throwable $error) {
        // This CLI branch cannot obtain helpers or bootstrap merely to report.
        try {
            $marker = 'TABLO_CAPABILITY_FAILURE ' . json_encode(['schema' => 'tablo/php-capability-failure/v1', 'category' => $category, 'missingExtension' => $missing], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (strlen($marker) <= 1_024) { fwrite(STDOUT, $marker); }
        } catch (Throwable $discarded) { unset($discarded); }
        throw $error;
    }
}

/** Parse only the exact finite marker in already-returned stdout, never a capture file.
 * @return array{category: string, missingExtension: ?string} */
function server_cell_capability_marker(#[\SensitiveParameter] string $bytes): array
{
    $unknown = ['category' => 'UNKNOWN', 'missingExtension' => null]; $prefix = 'TABLO_CAPABILITY_FAILURE ';
    if (strlen($bytes) > 1_024 || !str_starts_with($bytes, $prefix) || !str_ends_with($bytes, "\n")) { return $unknown; }
    $json = substr($bytes, offset: strlen($prefix), length: -1);
    try {
        /** @var mixed $record Core JSON decoding is followed by an exact finite shape check. */
        $record = json_decode($json, associative: true, depth: 4, flags: JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_keys($record) !== ['schema', 'category', 'missingExtension']
            || json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $json
            || ($record['schema'] ?? null) !== 'tablo/php-capability-failure/v1') { return $unknown; }
        /** @var mixed $category Only an allowlisted category can survive. */
        $category = $record['category'] ?? null;
        /** @var mixed $missing The extension is checked against the fixed nine names. */
        $missing = $record['missingExtension'] ?? null;
        if (!in_array($category, ['MISSING_EXTENSION', 'MISSING_SOURCE', 'MISSING_INI', 'MISSING_LOADED_INI', 'INI_MISMATCH', 'TEMP_MISMATCH', 'READY_WRITE_FAILED', 'UNKNOWN'], strict: true)
            || ($category === 'MISSING_EXTENSION' ? !in_array($missing, ['curl', 'openssl', 'pdo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'dom', 'xml', 'xmlwriter'], strict: true) : $missing !== null)) { return $unknown; }
        return ['category' => $category, 'missingExtension' => is_string($missing) ? $missing : null];
    } catch (Throwable) { return $unknown; }
}

/** Recognized unauthenticated message shape only; never origin, root cause or native proof.
 * Only the already-returned in-memory string is inspected; no arbitrary conversion. */
function server_cell_capability_stderr_kind(#[\SensitiveParameter] mixed $bytes): string
{
    return server_cell_capability_stderr_shape($bytes)['stderrKind'];
}

/** First refusal of the existing bounded parser, never a diagnosis of the host.
 * Only constant labels leave this helper; no input conversion or additional reads.
 * @return array{stderrKind: string, stderrReason: string} */
function server_cell_capability_stderr_shape(#[\SensitiveParameter] mixed $bytes): array
{
    if (!is_string($bytes)) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'UNAVAILABLE']; }
    if ($bytes === '') { return ['stderrKind' => 'NONE', 'stderrReason' => 'NONE']; }
    if (strlen($bytes) > 4_096) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => server_cell_capability_stderr_prefix($bytes)]; }
    try {
        if (preg_match('//u', $bytes) !== 1) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'UTF8_CHECK_FAILED']; }
        $text = str_replace("\r\n", replace: "\n", subject: $bytes);
        if (preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', str_replace("\n", replace: '', subject: $text)) !== 0) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'CONTROL_CHECK_FAILED']; }
        if (!str_ends_with($text, "\n")) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'TERMINATOR_MISSING']; }
        return server_cell_capability_stderr_records($text);
    } catch (Throwable $discarded) { unset($discarded); return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'CLASSIFIER_EXCEPTION']; }
}

/** Oversized buffers remain UNKNOWN. At most 1028 prefix bytes are inspected for
 * one complete first record, with the original optional separator and byte bound.
 * This unauthenticated prefix shape never describes the rest of the buffer. */
function server_cell_capability_stderr_prefix(#[\SensitiveParameter] string $bytes): string
{
    try {
        $head = substr($bytes, offset: 0, length: 1_028);
        $skip = match (true) { str_starts_with($head, "\r\n") => 2, str_starts_with($head, "\n") => 1, default => 0 };
        $head = substr($head, offset: $skip); $end = strpos($head, needle: "\n");
        if ($end === false) { return 'BUFFER_LIMIT'; }
        $record = substr($head, offset: 0, length: $end);
        if (str_ends_with($record, "\r")) { $record = substr($record, offset: 0, length: -1); }
        if (strlen($record) > 1_024 || preg_match('//u', $record) !== 1 || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $record) !== 0) { return 'BUFFER_LIMIT'; }
        $kind = server_cell_capability_stderr_record($record);
        return in_array($kind, ['MODULE_LOAD_MESSAGE', 'ZEND_LOADER_MESSAGE', 'DUPLICATE_MODULE_MESSAGE', 'PHP_STARTUP_WARNING', 'PHP_WARNING', 'PHP_DEPRECATION', 'PHP_ERROR'], strict: true)
            ? 'BUFFER_LIMIT_FIRST_RECORD_' . $kind : 'BUFFER_LIMIT';
    } catch (Throwable $discarded) { unset($discarded); return 'BUFFER_LIMIT'; }
}

/** Called only after the existing whole-buffer checks; preserves record order.
 * @return array{stderrKind: string, stderrReason: string} */
function server_cell_capability_stderr_records(#[\SensitiveParameter] string $text): array
{
    // Exactly one optional separator is admitted only at the buffer start.
    if (str_starts_with($text, "\n")) { $text = substr($text, offset: 1); }
    $records = explode("\n", substr($text, offset: 0, length: -1));
    if (count($records) > 8) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'RECORD_COUNT_LIMIT']; }
    $kind = 'UNKNOWN';
    foreach ($records as $record) {
        if (strlen($record) > 1_024) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'RECORD_BYTES_LIMIT']; }
        if ($record === '') { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'EMPTY_RECORD']; }
        $next = server_cell_capability_stderr_record($record);
        if ($next === 'UNKNOWN') { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'UNCLASSIFIED_RECORD']; }
        if ($kind !== 'UNKNOWN' && $kind !== $next) { return ['stderrKind' => 'UNKNOWN', 'stderrReason' => 'MIXED_KINDS']; }
        $kind = $next;
    }
    return ['stderrKind' => $kind, 'stderrReason' => 'RECOGNIZED'];
}

/** One complete printable record, called only after the whole-buffer UTF-8/control check. */
function server_cell_capability_stderr_record(#[\SensitiveParameter] string $record): string
{
    if (strlen($record) > 1_024) { return 'UNKNOWN'; }
    // The bare Zend duplicate formatter has no severity or source/line footer.
    // Its opaque slot is never captured or exported; only the complete shape counts.
    $bare = preg_match('/\ACannot load \S(?:.*\S)? - it was already loaded\z/uD', $record);
    if ($bare === false) { return 'UNKNOWN'; }
    if ($bare === 1) { return 'DUPLICATE_MODULE_MESSAGE'; }
    $parts = [];
    if (preg_match('/\A(?:PHP )?(Warning|Deprecated|Fatal error|Parse error): {1,2}(\S.*) in (\S.*) on line (0|[1-9][0-9]{0,9})\z/uD', $record, $parts) !== 1) { return 'UNKNOWN'; }
    /** @var array{0: string, 1: string, 2: string, 3: string, 4: string} $parts Required captures of a successful fixed pattern. */
    if ($parts[1] !== 'Warning') { return match ($parts[1]) { 'Deprecated' => 'PHP_DEPRECATION', 'Fatal error', 'Parse error' => 'PHP_ERROR', default => 'UNKNOWN' }; }
    $body = $parts[2]; $subject = $body . ' in ' . $parts[3] . ' on line ' . $parts[4];
    $patterns = ['MODULE_LOAD_MESSAGE' => "/\APHP Startup: Unable to load dynamic library '[^']+' \(tried: .+\) in Unknown on line 0\z/D",
        'ZEND_LOADER_MESSAGE' => '/\A[^ ]+ MUST be loaded as a Zend extension in Unknown on line 0\z/D',
        'DUPLICATE_MODULE_MESSAGE' => '/\AModule "[^"]+" is already loaded in Unknown on line 0\z/D'];
    foreach ($patterns as $kind => $pattern) {
        $match = preg_match($pattern, $subject);
        if ($match === false) { return 'UNKNOWN'; }
        if ($match === 1) { return $kind; }
    }
    // A malformed specialized prefix cannot fall back to generic warning framing.
    if (preg_match('/\A(?:PHP Startup: Unable to load dynamic library|[^ ]+ MUST be loaded\b|Module )/', $body) !== 0) { return 'UNKNOWN'; }
    if (str_starts_with($body, 'PHP Startup:')) { return preg_match('/\APHP Startup: \S.*\z/uD', $body) === 1 ? 'PHP_STARTUP_WARNING' : 'UNKNOWN'; }
    return 'PHP_WARNING';
}

/** @return array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string, stderrKind: string, stderrReason: string} */
function server_cell_capability_observation(#[\SensitiveParameter] array $result): array
{
    /** @var mixed $exit The PHP result is a reported observation, never native proof. */
    $exit = $result['exit_code'] ?? null;
    /** @var mixed $stdout Only equality and emptiness are projected. */
    $stdout = $result['stdout'] ?? null;
    /** @var mixed $stderr Only a bounded unauthenticated message shape is projected. */
    $stderr = $result['stderr'] ?? null;
    $marker = is_string($stdout) ? server_cell_capability_marker($stdout) : ['category' => 'UNKNOWN', 'missingExtension' => null];
    return ['schema' => 'tablo/php-capability-observation/v4', 'reportedExit' => array_key_exists('exit_code', $result) ? server_cell_capability_exit($exit) : 'UNKNOWN',
        'stdout' => match (true) { !is_string($stdout) => 'UNKNOWN', $stdout === "PRIVATE_PHP_READY\n" => 'READY', $stdout === '' => 'EMPTY', default => 'OTHER' },
        'stderr' => match (true) { !is_string($stderr) => 'UNKNOWN', $stderr === '' => 'EMPTY', default => 'NONEMPTY' }, ...$marker,
        ...server_cell_capability_stderr_shape($stderr)];
}

function server_cell_capability_exit(#[\SensitiveParameter] mixed $value): int|null|string
{
    return $value === null || (is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647) ? $value : 'UNKNOWN';
}

/** Exact versioned shape before any projection or serialization. */
function server_cell_capability_shape(array $value): bool
{
    $extra = match ($value['schema'] ?? null) {
        'tablo/php-capability-observation/v1' => [], 'tablo/php-capability-observation/v2' => ['stderrKind'],
        'tablo/php-capability-observation/v3', 'tablo/php-capability-observation/v4' => ['stderrKind', 'stderrReason'], default => null,
    };
    if ($extra === null || array_keys($value) !== ['schema', 'reportedExit', 'stdout', 'stderr', 'category', 'missingExtension', ...$extra]
        || !in_array($value['stdout'] ?? null, ['READY', 'EMPTY', 'OTHER', 'UNKNOWN'], strict: true)
        || !in_array($value['stderr'] ?? null, ['EMPTY', 'NONEMPTY', 'UNKNOWN'], strict: true)) { return false; }
    $kinds = ['EMPTY' => ['NONE'], 'UNKNOWN' => ['UNKNOWN'], 'NONEMPTY' => ['MODULE_LOAD_MESSAGE', 'ZEND_LOADER_MESSAGE', 'DUPLICATE_MODULE_MESSAGE', 'PHP_STARTUP_WARNING', 'PHP_WARNING', 'PHP_DEPRECATION', 'PHP_ERROR', 'UNKNOWN']];
    if ($extra !== [] && !in_array($value['stderrKind'] ?? null, $kinds[$value['stderr']] ?? [], strict: true)) { return false; }
    return !array_key_exists('stderrReason', $value) || server_cell_capability_stderr_reason_shape($value);
}

/** Exact keys and stderr/kind domains were checked before this coupling rule. */
function server_cell_capability_stderr_reason_shape(array $value): bool
{
    /** @var array{schema: string, stderr: string, stderrKind: string, stderrReason: mixed} $value */
    $reasons = match ($value['stderr']) {
        'EMPTY' => ['NONE'], 'UNKNOWN' => ['UNAVAILABLE'],
        default => $value['stderrKind'] !== 'UNKNOWN' ? ['RECOGNIZED'] : ['BUFFER_LIMIT', 'UTF8_CHECK_FAILED', 'CONTROL_CHECK_FAILED', 'TERMINATOR_MISSING',
            'RECORD_COUNT_LIMIT', 'RECORD_BYTES_LIMIT', 'EMPTY_RECORD', 'UNCLASSIFIED_RECORD', 'MIXED_KINDS', 'CLASSIFIER_EXCEPTION'],
    };
    if ($value['schema'] === 'tablo/php-capability-observation/v4' && $value['stderr'] === 'NONEMPTY' && $value['stderrKind'] === 'UNKNOWN') {
        foreach (['MODULE_LOAD_MESSAGE', 'ZEND_LOADER_MESSAGE', 'DUPLICATE_MODULE_MESSAGE', 'PHP_STARTUP_WARNING', 'PHP_WARNING', 'PHP_DEPRECATION', 'PHP_ERROR'] as $kind) {
            $reasons[] = 'BUFFER_LIMIT_FIRST_RECORD_' . $kind;
        }
    }
    return in_array($value['stderrReason'] ?? null, $reasons, strict: true);
}

/** @return ?array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string, stderrKind?: string, stderrReason?: string} */
function server_cell_capability_projection(array $value): ?array
{
    if (!server_cell_capability_shape($value)) { return null; }
    /** @var array{schema: mixed, reportedExit: mixed, stdout: mixed, stderr: mixed, category: mixed, missingExtension: mixed, stderrKind?: mixed, stderrReason?: mixed} $value */
    /** @var mixed $exit Every admitted exit must equal its bounded finite projection. */
    $exit = $value['reportedExit'];
    if ($exit !== server_cell_capability_exit($exit)) { return null; }
    if (!is_string($value['category']) || (!is_string($value['missingExtension']) && $value['missingExtension'] !== null)) { return null; }
    $extensionValid = $value['category'] === 'MISSING_EXTENSION'
        ? in_array($value['missingExtension'], ['curl', 'openssl', 'pdo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'dom', 'xml', 'xmlwriter'], strict: true)
        : in_array($value['category'], ['MISSING_SOURCE', 'MISSING_INI', 'MISSING_LOADED_INI', 'INI_MISMATCH', 'TEMP_MISMATCH', 'READY_WRITE_FAILED', 'UNKNOWN'], strict: true) && $value['missingExtension'] === null;
    if (!$extensionValid || ($value['category'] !== 'UNKNOWN' && $value['stdout'] !== 'OTHER')
        || [$exit, $value['stdout'], $value['stderr']] === [0, 'READY', 'EMPTY']) { return null; }
    /** @var array{schema: string, reportedExit: int|null|string, stdout: string, stderr: string, category: string, missingExtension: ?string, stderrKind?: string, stderrReason?: string} $value */
    return $value;
}

/** @param array{exit_code: mixed, stdout: mixed, stderr: mixed, ...} $ready */
function server_cell_capability_check(#[\SensitiveParameter] array $ready, ?array &$failure): void
{
    if ([$ready['exit_code'], $ready['stdout'], $ready['stderr']] !== [0, "PRIVATE_PHP_READY\n", '']) {
        $error = new RuntimeException('Actual private PHP capability failed.');
        try { $failure = server_cell_capability_observation($ready); } catch (Throwable $discarded) { unset($discarded); }
        throw $error;
    }
}

/** One literal directory, never PHP's platform-separated scan list. */
function server_cell_ini_scan_directory(string $runtime): string
{
    if ($runtime === '' || str_contains($runtime, "\0") || str_contains($runtime, PHP_OS_FAMILY === 'Windows' ? ';' : ':')) {
        throw new RuntimeException('Single private PHP scan directory required.');
    }
    return $runtime . '/private/php-ini-scan';
}

/** @return array<string, string> */
function server_cell_start_environment(array $plan): array
{
    $runtime = SqlColumn::string($plan, 'runtime'); $scan = server_cell_ini_scan_directory($runtime);
    $environment = [];
    foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'PATH', 'Path', 'PATHEXT', 'COMSPEC', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA',
        'TABLO_SERVER_OBSERVER_FILE', 'TABLO_SERVER_OBSERVER_SHA256', 'TABLO_TEST_BASELINE_DIRECTORY',
        'TABLO_SERVER_LINUX_ADAPTER', 'TABLO_SERVER_LINUX_ADAPTER_SHA256', 'TABLO_SERVER_LINUX_BUILD', 'TABLO_SERVER_LINUX_BUILD_SHA256',
        'TABLO_SERVER_LINUX_QUALIFICATION', 'TABLO_SERVER_LINUX_QUALIFICATION_SHA256',
        'TABLO_SERVER_LINUX_SHELL', 'TABLO_SERVER_LINUX_SHELL_SHA256',
        'TABLO_TEST_BASELINE_PHASE_FILE', 'TABLO_TEST_BASELINE_PHASE_SHA256'] as $name) {
        $value = getenv($name); if (is_string($value)) { $environment[$name] = $value; }
    }
    $composer = JsonRecord::checked($plan['composer'] ?? null); $lekalo = JsonRecord::checked($plan['lekalo'] ?? null);
    return $environment + ['TABLO_DB_DRIVER' => 'sqlite', 'TABLO_DB' => ':memory:', 'TABLO_TOKEN_KEY_FILE' => '',
        'TABLO_TEST_SOURCE_ROOT' => SqlColumn::string($plan, 'source'), 'TABLO_TEST_RUN_NONCE' => SqlColumn::string($plan, 'nonce'),
        // proc_open drops empty environment values; a nonempty owned directory survives nested PHP launches.
        'TABLO_TEST_DB_DRIVER' => SqlColumn::string($plan, 'selectedEngine'), 'PHPRC' => SqlColumn::string($plan, 'ini'), 'PHP_INI_SCAN_DIR' => $scan,
        'TMPDIR' => $runtime . '/tmp', 'TMP' => $runtime . '/tmp', 'TEMP' => $runtime . '/tmp',
        'TABLO_SESSION_EVIDENCE_DIRECTORY' => $runtime . '/evidence/closure',
        'COMPOSER_HOME' => $runtime . '/composer-home', 'COMPOSER_CACHE_DIR' => $runtime . '/composer-cache',
        'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_PROCESS_TIMEOUT' => '1800', 'LEKALO_BIN' => SqlColumn::string($lekalo, 'path'),
        'TABLO_TEST_COMPOSER_PHAR' => SqlColumn::string($composer, 'path'), 'TABLO_TEST_COMPOSER_SHA256' => SqlColumn::string($composer, 'sha256')];
}

function server_cell_secrets(array $plan, string $engine): void
{
    $services = JsonRecord::checked($plan['services'] ?? null); $service = JsonRecord::checked($services[$engine] ?? null);
    server_cell_private_write(SqlColumn::string($service, 'passwordFile'), \Tablo\JsonEncoding::encode([
        'writer' => bin2hex(random_bytes(32)), 'reader' => bin2hex(random_bytes(32)), 'migrator' => bin2hex(random_bytes(32)), 'deniedDdl' => bin2hex(random_bytes(32)), 'metadata' => bin2hex(random_bytes(32))]));
    $password = bin2hex(random_bytes(32));
    server_cell_private_write(SqlColumn::string($service, 'rootPasswordFile'), $password);
    server_cell_private_write(SqlColumn::string($service, 'rootOptionsFile'), "[client]\nuser=root\npassword=" . $password . "\nprotocol=socket\nsocket=" . SqlColumn::string($service, 'socket') . "\n");
}

function server_cell_tls(array $plan): void
{
    $directory = SqlColumn::string($plan, 'runtime') . '/private/tls'; $configuration = $directory . '/openssl.cnf';
    server_cell_private_write($configuration, bytes: "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");
    $options = ['config' => $configuration, 'private_key_bits' => 2_048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    if ($key === false) { throw new RuntimeException('Synthetic TLS CA key generation failed.'); }
    $request = openssl_csr_new(['commonName' => 'Tablo synthetic ' . SqlColumn::string($plan, 'nonce')], $key, $options);
    if (!$request instanceof OpenSSLCertificateSigningRequest) { throw new RuntimeException('Synthetic TLS CA request generation failed.'); }
    $pem = ''; $private = '';
    $certificate = openssl_csr_sign($request, ca_certificate: null, private_key: $key, days: 2, options: $options + ['x509_extensions' => 'ca']);
    if ($certificate === false || !openssl_x509_export($certificate, $pem) || !openssl_pkey_export($key, $private, options: $options)) { throw new RuntimeException('Synthetic TLS CA signing failed.'); }
    server_cell_private_write(SqlColumn::string($plan, 'ca'), $pem); server_cell_private_write($directory . '/ca.key', $private);
    $services = JsonRecord::checked($plan['services'] ?? null);
    foreach (array_keys($services) as $engine) { server_cell_tls_server($directory, (string) $engine, $certificate, $key, $options); }
}

function server_cell_tls_server(string $directory, string $engine, OpenSSLCertificate $ca, OpenSSLAsymmetricKey $authority, array $options): void
{
    $key = openssl_pkey_new($options);
    if ($key === false) { throw new RuntimeException('Synthetic TLS service key generation failed.'); }
    $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    if (!$request instanceof OpenSSLCertificateSigningRequest) { throw new RuntimeException('Synthetic TLS service request generation failed.'); }
    $pem = ''; $private = '';
    $certificate = openssl_csr_sign($request, $ca, $authority, days: 2, options: $options + ['x509_extensions' => 'server']);
    if ($certificate === false || !openssl_x509_export($certificate, $pem) || !openssl_pkey_export($key, $private, options: $options)) { throw new RuntimeException('Synthetic TLS service signing failed.'); }
    server_cell_private_write($directory . '/server-' . $engine . '.pem', $pem); server_cell_private_write($directory . '/server-' . $engine . '.key', $private);
    // The parent private directory remains0700; the bind-mounted key must be readable by the image's native mysql UID.
    if (!chmod($directory . '/server-' . $engine . '.key', permissions: 0o644) || !chmod($directory . '/server-' . $engine . '.pem', permissions: 0o644)
        || !chmod($directory . '/ca.pem', permissions: 0o644)) { throw new RuntimeException('Synthetic mounted TLS permissions unavailable.'); }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_execute(array &$context, ?array &$capabilityFailure = null): void
{
    $plan = $context['plan']; server_cell_observer_remaining();
    server_cell_verify_tools($plan);
    $ready = Subprocess::run([PHP_BINARY, SqlColumn::string($plan, 'source') . '/tools/server-cell.php', '--capability'], $context['directory'], $context['environment'],
        new CommandOptions(timeout: 30, cwd: SqlColumn::string($plan, 'source'), replacementEnvironment: $context['environment']));
    server_cell_capability_check($ready, $capabilityFailure);
    server_cell_admit_docker($context);
    $services = JsonRecord::checked($plan['services'] ?? null);
    foreach (array_keys($services) as $engine) { server_cell_acquire($context, (string) $engine); server_cell_start_service($context, (string) $engine); }
    $context['plan']['schema'] = 'tablo/server-cell/v1'; $context['plan']['sourceState'] = 'STARTED_PENDING_NATIVE_VERIFICATION';
    $path = SqlColumn::string($plan, 'runtime') . '/evidence/started-cell.json';
    server_cell_private_write($path, \Tablo\JsonEncoding::encode($context['plan'], JSON_PRETTY_PRINT));
    $environment = $context['environment'] + ['TABLO_TEST_CELL_PLAN' => $path, 'TABLO_TEST_CELL_PLAN_SHA256' => RequiredFile::digest($path)];
    server_cell_autoload($context, $environment);
    server_cell_observer_audit(SqlColumn::string($plan, 'runtime') . '/evidence/closure');
    server_cell_observer_remaining();
    fwrite(STDOUT, data: "Native service admission prepared; pool and selected PDO admission pending.\n");
}

/** Engine and catalog state remain in this one PHP controller; no wrapper is hidden from the cap.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_verify_and_close(array $context): void
{
    $plan = $context['plan'];
    require_once __DIR__ . '/verify-server.php';
    $arguments = ['--engine=' . SqlColumn::string($plan, 'selectedEngine')];
    if (($plan['cross'] ?? null) === true) { $arguments[] = '--cross'; }
    $summary = server_verify_run($arguments);
    $safe = server_cell_native_evidence($plan, \Tablo\JsonEncoding::encode($summary));
    $custody = server_cell_post_custody($plan);
    server_cell_cleanup($context);
    $closure = server_cell_observer_audit(SqlColumn::string($plan, 'runtime') . '/evidence/closure');
    server_cell_observer_remaining();
    server_cell_private_write(SqlColumn::string($plan, 'runtime') . '/evidence/cell-result.json', \Tablo\JsonEncoding::encode([
        'schema' => 'tablo/server-cell-result/v1', 'status' => 'passed', 'nonce' => $plan['nonce'] ?? null,
        'native' => ['mode' => 'callable-in-observed-controller', 'controllerPid' => getmypid(), 'summary' => $summary], 'safeNative' => $safe, 'custody' => $custody,
        'services' => $context['plan']['services'] ?? null, 'ownedServicesClosed' => true, 'observerClosureKnown' => true,
        'observerClosure' => $closure, 'completeIssueAcceptance' => false], JSON_PRETTY_PRINT));
    fwrite(STDOUT, data: "Required server cell passed; owned services closed.\n");
}

function server_cell_verify_tools(array $plan): void
{
    foreach (['docker', 'composer', 'lekalo', 'php'] as $name) {
        $tool = JsonRecord::checked($plan[$name] ?? null); $path = SqlColumn::string($tool, 'path');
        if (is_link($path) || !hash_equals(SqlColumn::string($tool, 'sha256'), RequiredFile::digest($path))) { throw new RuntimeException('Declared cell tool changed.'); }
    }
}

/** Publication contains only declared identities, statuses, counts and public session/binding facts. */
function server_cell_native_evidence(array $plan, string $output): array
{
    $summary = JsonRecord::decode($output); $engine = SqlColumn::string($plan, 'selectedEngine');
    $lanes = ($plan['cross'] ?? null) === true ? ['main', 'cross'] : ['main']; $path = SqlColumn::string($summary, 'evidence');
    if ([SqlColumn::string($summary, 'status'), SqlColumn::string($summary, 'engine'), $summary['lanes'] ?? null] !== ['passed', $engine, $lanes]
        || dirname($path) !== ($plan['runtime'] ?? null) || is_link($path)) { throw new RuntimeException('Exact native server evidence summary refused.'); }
    $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)), 64);
    if ([SqlColumn::string($record, 'status'), SqlColumn::string($record, 'engine'), $record['requiredLanes'] ?? null] !== ['passed', $engine, $lanes]) { throw new RuntimeException('Native server evidence status mismatch.'); }
    $baseline = JsonRecord::checked($record['baseline'] ?? null); $actual = JsonRecord::checked($record['actual'] ?? null);
    $native = server_cell_safe_lanes(JsonRecord::checked($actual['lanes'] ?? null), $lanes);
    return ['baseline' => $baseline['native'] ?? null, 'lanes' => $native, 'evidenceSha256' => RequiredFile::digest($path), 'sourceManifest' => $record['sourceManifest'] ?? null];
}

/** @param list<string> $lanes */
function server_cell_safe_lanes(array $actual, array $lanes): array
{
    $native = [];
    foreach ($lanes as $lane) {
        $result = JsonRecord::checked($actual[$lane] ?? null); $cases = JsonRecord::checked($result['cases'] ?? null); $safe = [];
        foreach (array_keys($cases) as $identity) {
            $case = JsonRecord::checked($cases[$identity] ?? null);
            $safe[$identity] = ['source' => $case['source'] ?? null, 'native' => $case['native'] ?? null, 'fixtureSlots' => $case['fixtureSlots'] ?? null];
        }
        $native[$lane] = ['cases' => $safe, 'readiness' => $result['readiness'] ?? null, 'bindings' => $result['bindings'] ?? null];
    }
    return $native;
}

/** Verification never rewrites source or recaptures expectations. Only the two offline autoload outputs may differ. */
function server_cell_post_custody(array $plan): array
{
    $manifest = JsonRecord::checked($plan['sourceManifest'] ?? null);
    $bytes = RequiredFile::bytes(file_get_contents(SqlColumn::string($manifest, 'path')));
    if (!hash_equals(SqlColumn::string($manifest, 'sha256'), hash('sha256', $bytes))) { throw new RuntimeException('Frozen original source inventory changed.'); }
    $record = JsonRecord::decode($bytes, 64);
    $root = SqlColumn::string($plan, 'source'); $source = JsonRecord::checked($record['files'] ?? null);
    server_cell_inventory_unchanged($root, $source);
    server_cell_inventory_unchanged($root, JsonRecord::checked($plan['magoFiles'] ?? null));
    $vendor = JsonRecord::checked($plan['vendorFiles'] ?? null); $changed = [];
    foreach (array_keys($vendor) as $index) {
        $file = JsonRecord::checked($vendor[$index] ?? null); $name = SqlColumn::string($file, 'path'); $path = $root . '/vendor/' . $name;
        if (is_link($path)) { throw new RuntimeException('Linked vendor output refused.'); }
        if (RequiredFile::digest($path) === SqlColumn::string($file, 'sha256')) { continue; }
        if (!in_array($name, ['composer/autoload_classmap.php', 'composer/autoload_static.php'], strict: true)) { throw new RuntimeException('Unreviewed locked vendor mutation.'); }
        $changed[] = ['path' => $name, 'sha256' => RequiredFile::digest($path)];
    }
    if (file_get_contents($root . '/.env') !== '') { throw new RuntimeException('Private empty environment changed.'); }
    server_cell_verify_tools($plan);
    return ['sourceFiles' => count($source), 'sourceUnchanged' => true, 'vendorFiles' => count($vendor), 'allowedAutoloadChanges' => $changed, 'emptyEnvironmentUnchanged' => true];
}

function server_cell_inventory_unchanged(string $root, array $files): void
{
    foreach (array_keys($files) as $index) {
        $file = JsonRecord::checked($files[$index] ?? null); $path = $root . '/' . SqlColumn::string($file, 'path');
        if (is_link($path) || !hash_equals(SqlColumn::string($file, 'sha256'), RequiredFile::digest($path))) { throw new RuntimeException('Frozen native cell input changed.'); }
    }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @param array<string, string> $environment */
function server_cell_autoload(array $context, array $environment): void
{
    $composer = JsonRecord::checked($context['plan']['composer'] ?? null);
    $autoload = Subprocess::run([PHP_BINARY, SqlColumn::string($composer, 'path'), '--no-plugins', '--no-scripts', '--no-interaction', 'dump-autoload', '--optimize'],
        $context['directory'], $environment, new CommandOptions(timeout: 120, cwd: SqlColumn::string($context['plan'], 'source'), replacementEnvironment: $environment));
    if ($autoload['exit_code'] !== 0) { throw new RuntimeException('Offline native autoload preparation failed.'); }
}

function server_cell_overall_remaining(float $deadline): float
{
    $remaining = $deadline - hrtime(true) / 1_000_000_000;
    if ($remaining <= 0) { throw new RuntimeException('Whole native server cell deadline exceeded.'); }
    return min(1_800, $remaining);
}

/** OCI configuration bytes, actual local identity and entrypoint bytes are checked before service startup.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_acquire(array &$context, string $engine): void
{
    $service = server_cell_service($context, $engine); $reference = SqlColumn::string($service, 'reference');
    if (($context['plan']['allowPull'] ?? null) === true) { server_cell_docker($context, ['pull', '--platform=linux/amd64', $reference], timeout: 300); }
    $image = server_cell_docker($context, ['image', 'inspect', $reference], timeout: 8);
    $rows = JsonRecord::decode($image['stdout']); $record = JsonRecord::checked($rows[0] ?? null);
    if (count($rows) !== 1 || [$record['Os'] ?? null, $record['Architecture'] ?? null] !== ['linux', 'amd64']) { throw new RuntimeException('Actual selected Linux/amd64 image missing.'); }
    server_cell_image_configuration($record, $service, $engine);
    $archive = SqlColumn::string($context['plan'], 'runtime') . '/evidence/' . $engine . '-image.tar';
    server_cell_docker($context, ['image', 'save', '--output', $archive, $reference], timeout: 180);
    $actual = server_cell_saved_config($archive, SqlColumn::string($service, 'configDigest'));
    if ([$actual['architecture'] ?? null, $actual['os'] ?? null] !== ['amd64', 'linux']) { throw new RuntimeException('Actual OCI configuration platform mismatch.'); }
    server_cell_service_fact($context, $engine, name: 'imageId', value: SqlColumn::string($record, 'Id'));
    server_cell_journal($context, $engine . '-image-attested', ['imageId' => $record['Id'] ?? null, 'configSha256' => substr(SqlColumn::string($service, 'configDigest'), offset: 7)]);
}

function server_cell_image_configuration(array $record, array $service, string $engine): void
{
    $config = JsonRecord::checked($record['Config'] ?? null); $expected = $engine === 'mysql' ? ['/entrypoint.sh'] : ['docker-entrypoint.sh'];
    if (($config['Entrypoint'] ?? null) !== $expected || array_keys(JsonRecord::checked($config['Volumes'] ?? [])) !== ($service['declaredVolumes'] ?? null)) {
        throw new RuntimeException('Immutable image entrypoint or volume declarations changed.');
    }
    $reference = SqlColumn::string($service, 'reference'); $digest = substr($reference, offset: (int) strrpos($reference, needle: '@') + 1);
    $values = JsonRecord::checked($record['RepoDigests'] ?? null);
    foreach (array_keys($values) as $index) { if (str_ends_with(SqlColumn::string($values, (string) $index), '@' . $digest)) { return; } }
    throw new RuntimeException('Actual immutable image repository digest absent.');
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_service_fact(array &$context, string $engine, string $name, string $value): void
{
    $services = JsonRecord::checked($context['plan']['services'] ?? null); $service = JsonRecord::checked($services[$engine] ?? null);
    $service[$name] = $value; $services[$engine] = $service; $context['plan']['services'] = $services;
}

/** Reads only an exact regular config member. A tar link or unrelated filesystem path is never followed. */
function server_cell_saved_config(string $archive, string $digest): array
{
    $size = filesize($archive);
    if (is_link($archive) || !is_int($size) || $size < 512 || $size > 2_147_483_648) { throw new RuntimeException('Finite image archive refused.'); }
    $hash = substr($digest, offset: 7); $names = [$hash . '.json', 'blobs/sha256/' . $hash]; $handle = fopen($archive, mode: 'rb');
    if (!is_resource($handle)) { throw new RuntimeException('Owned saved image unavailable.'); }
    try {
        while (!feof($handle)) {
            $header = fread($handle, length: 512);
            if ($header === false || strlen($header) !== 512 || $header === str_repeat("\0", times: 512)) { break; }
            $name = rtrim(substr($header, offset: 0, length: 100), characters: "\0");
            $prefix = rtrim(substr($header, offset: 345, length: 155), characters: "\0"); if ($prefix !== '') { $name = $prefix . '/' . $name; }
            $length = server_cell_tar_size(substr($header, offset: 124, length: 12));
            if (in_array($name, $names, strict: true)) { return server_cell_config_member($handle, $header[156], $length, $hash); }
            if (fseek($handle, offset: (int) (ceil($length / 512) * 512), whence: SEEK_CUR) !== 0) { throw new RuntimeException('Saved image member seek failed.'); }
        }
    } finally { fclose($handle); }
    throw new RuntimeException('Pinned original OCI configuration bytes absent.');
}

function server_cell_tar_size(string $value): int
{
    $value = trim($value, characters: "\0 ");
    if (preg_match('/\A[0-7]{1,11}\z/D', $value) !== 1) { throw new RuntimeException('Finite image member size refused.'); }
    $size = octdec($value);
    if (!is_int($size) || $size > 2_147_483_648) { throw new RuntimeException('Finite image member size exceeded.'); }
    return $size;
}

/** @param resource $handle */
function server_cell_config_member(mixed $handle, string $type, int $size, string $hash): array
{
    if (!in_array($type, ["\0", '0'], strict: true) || $size < 1 || $size > 1_048_576) { throw new RuntimeException('Pinned OCI member must be a bounded regular file.'); }
    $bytes = fread($handle, $size);
    if (!is_string($bytes) || strlen($bytes) !== $size || !hash_equals($hash, hash('sha256', $bytes))) { throw new RuntimeException('Pinned actual OCI configuration hash mismatch.'); }
    return JsonRecord::decode($bytes, 64);
}

/** The mutation intent is durable before create; unknown outcomes are retained and never adopted by name.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_start_service(array &$context, string $engine): void
{
    $service = server_cell_service($context, $engine); $nonce = SqlColumn::string($context['plan'], 'nonce');
    foreach (['container', 'network', 'volume'] as $kind) { server_cell_resource_absent($context, $engine, $kind); }
    server_cell_journal($context, $engine . '-network-intent', ['name' => $service['network'] ?? null]);
    $network = server_cell_docker($context, ['network', 'create', '--driver', 'bridge', '--label', 'tablo.issue39.nonce=' . $nonce, SqlColumn::string($service, 'network')], timeout: 8);
    $networkId = trim($network['stdout']);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $networkId) !== 1) { throw new RuntimeException('Actual created network ID unknown.'); }
    server_cell_service_fact($context, $engine, name: 'networkId', value: $networkId);
    server_cell_journal($context, $engine . '-network-created', ['id' => $networkId]);
    server_cell_journal($context, $engine . '-volume-intent', ['name' => $service['volume'] ?? null]);
    $volume = server_cell_docker($context, ['volume', 'create', '--label', 'tablo.issue39.nonce=' . $nonce, SqlColumn::string($service, 'volume')], timeout: 8);
    if (trim($volume['stdout']) !== ($service['volume'] ?? null)) { throw new RuntimeException('Actual owned volume creation unknown.'); }
    server_cell_journal($context, $engine . '-volume-created', ['name' => $service['volume'] ?? null]);
    $arguments = server_cell_create_arguments($context['plan'], $engine);
    server_cell_journal($context, $engine . '-container-intent', ['name' => $service['container'] ?? null]);
    $created = server_cell_docker($context, $arguments, timeout: 8); $id = trim($created['stdout']);
    if (preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) { throw new RuntimeException('Actual created container ID unknown.'); }
    server_cell_service_fact($context, $engine, name: 'containerId', value: $id);
    server_cell_journal($context, $engine . '-container-created', ['id' => $id]);
    server_cell_entrypoint_attest($context, $engine);
    server_cell_service_identity($context, $engine);
    $deadline = hrtime(true) / 1_000_000_000 + 180;
    server_cell_docker($context, ['start', $id], server_cell_service_remaining($deadline, maximum: 8));
    server_cell_prepare_client($context, $engine, $deadline);
    server_cell_authenticated_start($context, $engine, $deadline);
    server_cell_attest_publication($context, $engine, $deadline);
}

/** A nonzero exit alone does not prove absence; daemon/transport errors never authorize create.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 */
function server_cell_resource_absent(array $context, string $engine, string $kind): void
{
    $lease = server_cell_observer_begin(8, label: 'native/resource-absence');
    $name = server_cell_absence_name($context['plan'], $engine, $kind);
    $arguments = $kind === 'container' ? ['inspect', $name] : [$kind, 'inspect', $name];
    server_cell_tool($context['plan']); $environment = server_cell_docker_environment($context);
    $command = server_cell_docker_arguments($context, $arguments, PHP_OS_FAMILY);
    $result = Subprocess::run($command, $context['directory'], $environment, new CommandOptions(timeout: 8, replacementEnvironment: $environment));
    $receipt = SubprocessReceipt::read($result['files']['receipt']);
    if (!server_cell_absence_record($result, $receipt, $kind, $name)) { throw new RuntimeException('Fresh exact resource absence is not established.'); }
    server_cell_observer_finish($lease, server_cell_observer_closed($result, $receipt));
}

function server_cell_absence_name(array $plan, string $engine, string $kind): string
{
    $nonce = SqlColumn::string($plan, 'nonce');
    if (preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !in_array($engine, ['mysql', 'mariadb'], strict: true)) {
        throw new RuntimeException('Exact fresh resource ownership refused.');
    }
    $suffix = match ($kind) { 'container' => '', 'network' => '-net', 'volume' => '-data', default => throw new RuntimeException('Explicit resource kind refused.') };
    $expected = 'tablo-i39-' . substr($nonce, offset: 0, length: 12) . '-' . $engine . $suffix;
    $services = JsonRecord::checked($plan['services'] ?? null); $service = JsonRecord::checked($services[$engine] ?? null);
    if (SqlColumn::string($service, $kind) !== $expected) { throw new RuntimeException('Exact fresh resource ownership refused.'); }
    return $expected;
}

/** A specific nonzero inspect outcome proves absence only for its exact owned kind/name. */
function server_cell_absence_record(array $result, array $receipt, string $kind, string $name): bool
{
    if ([$receipt['closed'] ?? null, $receipt['root_exit_observed'] ?? null, $receipt['exit_code'] ?? null, $result['exit_code'] ?? null]
        !== [true, true, 1, 1] || ($result['stdout'] ?? null) !== "[]\n") { return false; }
    if (!is_string($result['stderr'] ?? null)) { return false; }
    $line = $result['stderr'];
    $line = str_replace("\r\n", replace: "\n", subject: $line);
    if (str_ends_with($line, needle: "\n")) { $line = substr($line, offset: 0, length: -1); }
    $accepted = match ($kind) {
        'container' => ['error: no such object: ' . $name, 'Error: No such object: ' . $name, 'Error response from daemon: No such container: ' . $name],
        'network' => ['Error response from daemon: network ' . $name . ' not found', 'Error response from daemon: No such network: ' . $name],
        'volume' => ['Error response from daemon: get ' . $name . ': no such volume', 'Error response from daemon: No such volume: ' . $name],
        default => [],
    };
    return in_array($line, $accepted, strict: true);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @return array{directory: string, options: string, socket: string, containerId: string} */
function server_cell_client_paths(array $context, string $engine): array
{
    $name = server_cell_absence_name($context['plan'], $engine, kind: 'container');
    $service = server_cell_service($context, $engine); $socket = '/tmp/' . $name . '.sock';
    $id = SqlColumn::string($service, 'containerId');
    if (preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1 || SqlColumn::string($service, 'socket') !== $socket) {
        throw new RuntimeException('Exact native client container/socket ownership refused.');
    }
    $directory = '/run/tablo-i39-' . SqlColumn::string($context['plan'], 'nonce') . '-' . $engine . '-client';
    return ['directory' => $directory, 'options' => $directory . '/root-client.cnf', 'socket' => $socket, 'containerId' => $id];
}

/** No password or SQL enters argv; stdin remains the actual native SQL channel.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @return list<string> */
function server_cell_client_arguments(array $context, string $engine): array
{
    $paths = server_cell_client_paths($context, $engine);
    return ['exec', '-i', $paths['containerId'], $engine === 'mysql' ? 'mysql' : 'mariadb',
        '--defaults-extra-file=' . $paths['options'], '--protocol=socket', '--socket=' . $paths['socket'],
        '--batch', '--skip-column-names', '--raw'];
}

/** Static shell text contains no generated secret or interpolated path. */
function server_cell_client_copy_script(): string
{
    $script = <<<'SH'
umask 077
test ! -e "$1"
test ! -L "$1"
mkdir -m 700 "$1"
cat /run/secrets/root-client.cnf > "$1/root-client.cnf"
chmod 600 "$1/root-client.cnf"
test "$(stat -c %u "$1")" = 0
test "$(stat -c %u "$1/root-client.cnf")" = 0
test "$(stat -c %a "$1")" = 700
test "$(stat -c %a "$1/root-client.cnf")" = 600
printf 'OWNED_CLIENT_READY\n'
SH;
    return str_replace("\r\n", replace: "\n", subject: $script);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_prepare_client(array $context, string $engine, float $deadline): void
{
    $paths = server_cell_client_paths($context, $engine);
    $result = server_cell_docker($context, ['exec', $paths['containerId'], '/bin/sh', '-eu', '-c', server_cell_client_copy_script(),
        'tablo-client-prepare', $paths['directory']], server_cell_service_remaining($deadline, maximum: 8));
    if ([$result['stdout'], $result['stderr']] !== ["OWNED_CLIENT_READY\n", '']) { throw new RuntimeException('Actual private native client configuration refused.'); }
    server_cell_journal($context, $engine . '-client-ready', ['directory' => $paths['directory'], 'options' => $paths['options'],
        'socket' => $paths['socket'], 'uid' => 0, 'directoryMode' => '0700', 'optionsMode' => '0600']);
}

/** @return list<string> */
function server_cell_create_arguments(array $plan, string $engine): array
{
    $services = JsonRecord::checked($plan['services'] ?? null); $service = JsonRecord::checked($services[$engine] ?? null);
    $tls = SqlColumn::string($plan, 'runtime') . '/private/tls';
    $arguments = ['create', '--platform=linux/amd64', '--pull=never', '--name', SqlColumn::string($service, 'container'),
        '--label', 'tablo.issue39.nonce=' . SqlColumn::string($plan, 'nonce'), '--network', SqlColumn::string($service, 'networkId'),
        '--publish', '127.0.0.1:' . SqlColumn::integer($service, 'port') . ':3306',
        '--mount', 'type=volume,src=' . SqlColumn::string($service, 'volume') . ',dst=/var/lib/mysql'];
    foreach ([SqlColumn::string($service, 'rootPasswordFile') => 'root-password', SqlColumn::string($service, 'rootOptionsFile') => 'root-client.cnf',
        $tls . '/ca.pem' => 'ca.pem', $tls . '/server-' . $engine . '.pem' => 'server.pem', $tls . '/server-' . $engine . '.key' => 'server.key'] as $source => $target) {
        $arguments[] = '--mount'; $arguments[] = 'type=bind,src=' . $source . ',dst=/run/secrets/' . $target . ',readonly';
    }
    $startup = $engine === 'mysql' ? ['--env', 'MYSQL_ROOT_PASSWORD=/run/secrets/root-password']
        : ['--env', 'MARIADB_ROOT_PASSWORD_FILE=/run/secrets/root-password', '--env', 'MARIADB_ROOT_HOST=localhost'];
    $arguments = [...$arguments, ...$startup];
    return [...$arguments, SqlColumn::string($service, 'reference'), $engine === 'mysql' ? 'mysqld' : 'mariadbd', '--skip-name-resolve',
        '--ssl-ca=/run/secrets/ca.pem', '--ssl-cert=/run/secrets/server.pem', '--ssl-key=/run/secrets/server.key',
        '--require-secure-transport=ON', '--socket=' . SqlColumn::string($service, 'socket')];
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_entrypoint_attest(array $context, string $engine): void
{
    $service = server_cell_service($context, $engine); $output = SqlColumn::string($context['plan'], 'runtime') . '/evidence/' . $engine . '-entrypoint';
    server_cell_docker($context, ['cp', SqlColumn::string($service, 'containerId') . ':' . SqlColumn::string($service, 'entrypointPath'), $output], timeout: 8);
    if (is_link($output) || !hash_equals(SqlColumn::string($service, 'entrypointSha256'), RequiredFile::digest($output))) { throw new RuntimeException('Actual immutable entrypoint bytes mismatch.'); }
    server_cell_journal($context, $engine . '-entrypoint-attested', ['sha256' => RequiredFile::digest($output)]);
}

/** Native authenticated socket readiness is separate from the later fresh verified-TLS PDO admission.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_authenticated_start(array $context, string $engine, float $deadline): void
{
    $facts = null;
    do {
        if (server_cell_native_ready($context, $engine, server_cell_service_remaining($deadline, maximum: 8))) {
            $facts = server_cell_admin($context, $engine, sql: "SELECT VERSION(); SELECT Host FROM mysql.user WHERE User='root' ORDER BY Host; " . server_cell_native_readiness_sql(), remaining: server_cell_service_remaining($deadline, maximum: 30)); break;
        }
        usleep(100_000);
    } while (hrtime(true) / 1_000_000_000 < $deadline);
    if (!is_string($facts)) { throw new RuntimeException('Authenticated native service readiness deadline exceeded.'); }
    $service = server_cell_service($context, $engine); $lines = server_cell_native_fact_lines($facts);
    $transport = $lines[count($lines) - 1] ?? null; $rootHosts = array_slice($lines, offset: 1, length: -1);
    $version = $lines[0]; $prefix = SqlColumn::string($service, 'expectedVersionPrefix');
    if (!($version === $prefix || str_starts_with($version, $prefix . '-')) || (count($rootHosts) !== 1 || $rootHosts[0] !== 'localhost') || $transport !== "0\t3306\t1") {
        server_cell_journal($context, $engine . '-version-refused', ['version' => $version, 'rootHosts' => $rootHosts, 'transportRow' => $transport]);
        throw new RuntimeException('Actual package version or root locality requires review.');
    }
    $arguments = $engine === 'mysql' ? ['rpm', '-q', 'mysql-community-server-minimal'] : ['dpkg-query', '-W', 'mariadb-server-core'];
    $package = server_cell_docker($context, ['exec', SqlColumn::string($service, 'containerId'), ...$arguments], server_cell_service_remaining($deadline, maximum: 8));
    if (!str_contains($package['stdout'], $prefix)) { throw new RuntimeException('Actual native package version differs from reviewed pin.'); }
    if (hrtime(true) / 1_000_000_000 > $deadline) { throw new RuntimeException('Owned service startup deadline exceeded.'); }
    server_cell_journal($context, $engine . '-native-ready', ['version' => $version, 'rootHosts' => $rootHosts, 'transportRow' => $transport,
        'nativeFactsStdout' => $facts, 'package' => trim($package['stdout'])]);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_native_ready(array $context, string $engine, float $timeout): bool
{
    $lease = server_cell_observer_begin($timeout, label: 'native/readiness');
    server_cell_tool($context['plan']); $environment = server_cell_docker_environment($context);
    $command = server_cell_native_ready_command($context, $engine, PHP_OS_FAMILY);
    $result = Subprocess::run($command, $context['directory'], $environment,
        new CommandOptions(timeout: $timeout, input: server_cell_native_readiness_sql(), replacementEnvironment: $environment));
    $receipt = SubprocessReceipt::read($result['files']['receipt']);
    $ready = server_cell_native_ready_outcome($result, $receipt);
    server_cell_observer_finish($lease, server_cell_observer_closed($result, $receipt));
    return $ready;
}

/** Preserve interior fields while accepting the native client's LF and CRLF line endings.
 * @return non-empty-list<string> */
function server_cell_native_fact_lines(string $facts): array
{
    return explode("\n", trim(str_replace("\r\n", replace: "\n", subject: $facts)));
}

/** A bootstrap socket may authenticate while --skip-networking still disables the final TCP transport. */
function server_cell_native_readiness_sql(): string
{
    return 'SELECT @@skip_networking, @@port, @@require_secure_transport;';
}

/** Only observed numeric closure can establish readiness; malformed or signal-only outcomes remain unknown. */
function server_cell_native_ready_outcome(array $result, array $receipt): bool
{
    $exit = SqlColumn::integer($result, 'exit_code');
    if ([$receipt['closed'] ?? null, $receipt['root_exit_observed'] ?? null, $receipt['exit_code'] ?? null,
        $receipt['signaled'] ?? false, $receipt['term_signal'] ?? null] !== [true, true, $exit, false, null]
        || SqlColumn::integer($receipt, 'pid') <= 0) {
        throw new RuntimeException('Native readiness child outcome unknown.');
    }
    return $exit === 0 && ($result['stderr'] ?? null) === ''
        && in_array($result['stdout'] ?? null, ["0\t3306\t1", "0\t3306\t1\n", "0\t3306\t1\r\n"], strict: true);
}

/** Every child consumes the same service180 budget; a late probe cannot replenish it. */
function server_cell_service_remaining(float $deadline, float $maximum): float
{
    $remaining = $deadline - hrtime(true) / 1_000_000_000;
    if ($remaining <= 0 || $maximum <= 0 || $maximum > 30) { throw new RuntimeException('Owned service startup deadline exceeded.'); }
    return min($maximum, $remaining);
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_journal(array $context, string $stage, array $facts): void
{
    server_cell_private_write(SqlColumn::string($context['plan'], 'runtime') . '/evidence/' . $stage . '.json',
        \Tablo\JsonEncoding::encode(['schema' => 'tablo/server-cell-stage/v1', 'nonce' => $context['plan']['nonce'] ?? null,
            'stage' => $stage, 'utc' => gmdate('c'), 'facts' => $facts], JSON_PRETTY_PRINT));
}

/** Only successful native verification reaches cleanup. Failed or unknown services retain their positive receipts.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_cleanup(array $context): void
{
    $deadline = hrtime(true) / 1_000_000_000 + 30; $services = JsonRecord::checked($context['plan']['services'] ?? null);
    foreach (array_reverse(array_keys($services)) as $engine) {
        $engine = (string) $engine; $service = server_cell_service($context, $engine); server_cell_service_identity($context, $engine, $deadline);
        server_cell_network_identity($context, $engine, $deadline);
        foreach ([['stop', '--time=5', SqlColumn::string($service, 'containerId')], ['rm', SqlColumn::string($service, 'containerId')],
            ['volume', 'rm', SqlColumn::string($service, 'volume')], ['network', 'rm', SqlColumn::string($service, 'networkId')]] as $arguments) {
            server_cell_docker($context, $arguments, min(8, server_cell_remaining($deadline)));
        }
        server_cell_journal($context, $engine . '-closed', ['containerId' => $service['containerId'] ?? null, 'volume' => $service['volume'] ?? null, 'networkId' => $service['networkId'] ?? null]);
    }
    server_cell_service_remaining($deadline, maximum: 8); // No late cleanup success after the same absolute30.
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_network_identity(array $context, string $engine, ?float $deadline = null): void
{
    $service = server_cell_service($context, $engine); $result = server_cell_docker($context, ['network', 'inspect', SqlColumn::string($service, 'networkId')],
        timeout: $deadline === null ? 8 : server_cell_service_remaining($deadline, maximum: 8));
    $rows = JsonRecord::decode($result['stdout']); $record = JsonRecord::checked($rows[0] ?? null); $labels = JsonRecord::checked($record['Labels'] ?? null);
    if (count($rows) !== 1 || [$record['Id'] ?? null, $record['Name'] ?? null, $labels['tablo.issue39.nonce'] ?? null, $record['Driver'] ?? null, $record['Internal'] ?? null]
        !== [$service['networkId'] ?? null, $service['network'] ?? null, $context['plan']['nonce'] ?? null, 'bridge', false]) { throw new RuntimeException('Exact created network identity changed.'); }
}

/** Fresh account/PDO/TLS admission against empty owned catalogs precedes the unchanged SQLite verification.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_availability(array $context): array
{
    $services = JsonRecord::checked($context['plan']['services'] ?? null); $fixtures = []; $nonce = SqlColumn::string($context['plan'], 'nonce');
    foreach (array_keys($services) as $engine) {
        $engine = (string) $engine; $catalog = ServerFixturePlan::slots($nonce, $engine)[7] ?? throw new RuntimeException('Finite readiness slot absent.');
        $fixtures[] = ['alias' => 'cell-readiness', 'engine' => $engine, 'initial' => 'empty', 'catalog' => $catalog];
    }
    $base = SqlColumn::string($context['plan'], 'runtime') . '/availability'; server_cell_directory($base);
    $environment = server_cell_case_manifests($context, $fixtures, $base, SqlColumn::string($context['plan'], 'selectedEngine'));
    $path = $base . '/availability.json';
    server_cell_private_write($path, \Tablo\JsonEncoding::encode(['schema' => 'tablo/server-pdo-availability/v1', 'nonce' => $nonce, 'fixtures' => $fixtures]));
    $child = Subprocess::run([PHP_BINARY, server_cell_script($context, relative: 'tools/server-cell.php'), '--availability=' . $path], $context['directory'], $environment,
        new CommandOptions(timeout: 30, replacementEnvironment: $environment));
    if ($child['exit_code'] !== 0 || $child['stderr'] !== '') { throw new RuntimeException('Actual fresh TLS PDO availability failed.'); }
    return ['actual' => JsonRecord::decode($child['stdout']), 'capture' => $child['files']];
}

/** Child scripts must resolve inside the prepared source whose offline autoload was generated.
 * @param array{plan: array, ...} $context */
function server_cell_script(array $context, string $relative): string
{
    if (!in_array($relative, ['tools/server-cell.php', 'tests/server-child.php'], strict: true)) { throw new RuntimeException('Native child script selection refused.'); }
    return SqlColumn::string($context['plan'], 'source') . '/' . $relative;
}

function server_cell_availability_child(string $path): void
{
    $engine = null; $stage = 'bootstrap';
    try {
        $root = str_replace('\\', replace: '/', subject: dirname(__DIR__)); require_once $root . '/tools/reviewed-dependencies.php'; verify_worker_dependencies($root); require_once $root . '/vendor/autoload.php';
        $stage = 'input';
        if (is_link($path) || basename($path) !== 'availability.json') { throw new RuntimeException('Explicit owned PDO availability input required.'); }
        $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($path)));
        if ([$record['schema'] ?? null, $record['nonce'] ?? null] !== ['tablo/server-pdo-availability/v1', getenv('TABLO_TEST_RUN_NONCE')]) { throw new RuntimeException('PDO availability input identity refused.'); }
        $fixtures = JsonRecord::checked($record['fixtures'] ?? null); $facts = [];
        foreach (array_keys($fixtures) as $index) {
            $fixture = JsonRecord::checked($fixtures[$index] ?? null); $engine = SqlColumn::string($fixture, 'engine');
            $peer = getenv('TABLO_TEST_DB_DRIVER') === $engine ? null : $engine;
            $stage = 'fixture'; $owner = new EngineFixture('cell-readiness', $peer); $db = null;
            try {
                $stage = 'connection'; $db = $owner->maintenance();
                $stage = 'objects';
                if (\Tablo\ServerSchema::objects($db) !== []) { throw new RuntimeException('Native readiness requires its unchanged empty catalog.'); }
                $stage = 'facts';
                $facts[$engine] = ['identity' => \Tablo\ServerSchema::rows($db, 'SELECT VERSION() AS version,DATABASE() AS catalog,CONNECTION_ID() AS connection_id'),
                    'tls' => \Tablo\ServerSchema::rows($db, "SHOW SESSION STATUS LIKE 'Ssl_cipher'"), 'actualEngine' => \Tablo\DatabaseSession::get($db)?->configuration->engine];
            } finally { $db = null; $owner->close(); }
        }
        fwrite(STDOUT, \Tablo\JsonEncoding::encode($facts) . "\n");
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(server_cell_availability_failure($error, $engine, $stage), JSON_THROW_ON_ERROR) . "\n");
        throw $error;
    }
}

/** Fixed, credential-free fixture classification; never serialize a message, trace, previous error or native text.
 * @return array{schema: string, engine: ?string, stage: string, type: string, category: ?string, sqlState: ?string, nativeCode: ?int, nativeReason: string, source: ?string, line: ?int} */
function server_cell_availability_failure(#[\SensitiveParameter] Throwable $error, ?string $engine, string $stage): array
{
    $information = match (true) { $error instanceof PDOException => $error->errorInfo,
        $error instanceof \Tablo\DatabaseFailure => [$error->sqlState, $error->nativeCode], default => null };
    /** @var mixed $state The native extension supplies an untyped field; only a checked SQLSTATE is retained. */
    $state = $information[0] ?? null;
    /** @var mixed $native Native diagnostics may be malformed; strings never become integer exit/code evidence. */
    $native = $information[1] ?? null;
    $type = match (true) { $error instanceof \Tablo\DatabaseFailure => 'database', $error instanceof PDOException => 'pdo',
        $error instanceof RuntimeException => 'runtime', $error instanceof Error => 'error', default => 'other' };
    $category = $error instanceof \Tablo\DatabaseFailure ? $error->category : null;
    $reason = match (true) { $error instanceof \Tablo\DatabaseFailure => $error->nativeReason,
        $error instanceof PDOException && class_exists(\Tablo\DatabaseFailure::class, autoload: false) => \Tablo\DatabaseFailure::fromNative($error)->nativeReason, default => 'unknown' };
    $categories = ['configuration', 'driver', 'engine', 'schema', 'key', 'busy', 'uncertain', 'constraint', 'unavailable'];
    return ['schema' => 'tablo/server-availability-failure/v1',
        'engine' => in_array($engine, ['mysql', 'mariadb'], strict: true) ? $engine : null,
        'stage' => in_array($stage, ['bootstrap', 'input', 'fixture', 'connection', 'objects', 'facts'], strict: true) ? $stage : 'unknown',
        'type' => $type, 'category' => in_array($category, $categories, strict: true) ? $category : null,
        'sqlState' => is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/D', $state) === 1 ? $state : null,
        'nativeCode' => is_int($native) && $native >= 0 && $native <= 65_535 ? $native : null, 'nativeReason' => $reason] + server_cell_availability_origin($error);
}

/** @return array{source: ?string, line: ?int} */
function server_cell_availability_origin(#[\SensitiveParameter] Throwable $error): array
{
    $origin = str_replace('\\', replace: '/', subject: $error->getFile());
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__)) . '/';
    $relative = str_starts_with($origin, $root) ? substr($origin, offset: strlen($root)) : '';
    $owned = preg_match('~\A(?:app|tools|tests/(?:Support|Server|ServerCross))/[A-Za-z0-9_-]+\.php\z~D', $relative) === 1;
    return ['source' => $owned ? $relative : null, 'line' => $owned && $error->getLine() > 0 ? $error->getLine() : null];
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    /** @var list<string> $argv */
    try { exit(server_cell_entry(array_slice($argv, offset: 1))); }
    catch (Throwable) { fwrite(STDERR, data: "Native server cell failed.\n"); exit(1); }
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_attest_publication(array $context, string $engine, float $deadline): void
{
    $running = server_cell_service_identity($context, $engine, $deadline);
    $port = SqlColumn::integer(server_cell_service($context, $engine), 'port');
    server_cell_journal($context, $engine . '-publication-initial', ['declaredHost' => '127.0.0.1', 'declaredPort' => $port] + server_cell_publication_fields($running));
    server_cell_require_publication($running, $port);
    server_cell_service_remaining($deadline, maximum: 8);
}

/** Selected public port fields only; missing and null remain distinct in the retained evidence.
 * @return array{requestedPresent: bool, requested: mixed, actualPresent: bool, actual: mixed} */
function server_cell_publication_fields(array $record): array
{
    $host = is_array($record['HostConfig'] ?? null) ? $record['HostConfig'] : [];
    $network = is_array($record['NetworkSettings'] ?? null) ? $record['NetworkSettings'] : [];
    return ['requestedPresent' => array_key_exists('PortBindings', $host),
        'requested' => $host['PortBindings'] ?? null,
        'actualPresent' => array_key_exists('Ports', $network),
        'actual' => $network['Ports'] ?? null];
}

/** HostConfig is intent; admission also requires the daemon's actual post-start publication. */
function server_cell_require_publication(array $record, int $port): array
{
    $fields = server_cell_publication_fields($record);
    if (!server_cell_loopback_mapping($fields['requested'], $port) || !server_cell_actual_loopback_mapping($fields['actual'], $port)) {
        throw new RuntimeException('Exact loopback port publication not observed.');
    }
    return $fields;
}

/** Unpublished EXPOSE metadata is distinct from an additional host publication. */
function server_cell_actual_loopback_mapping(mixed $ports, int $port): bool
{
    if (!is_array($ports) || count($ports) > 128 || !array_key_exists('3306/tcp', $ports)) { return false; }
    foreach (array_keys($ports) as $name) {
        if ($name === '3306/tcp') { continue; }
        $parts = [];
        if ($ports[$name] !== null || !is_string($name)
            || preg_match('/\A([1-9][0-9]{0,4})(?:-([1-9][0-9]{0,4}))?\/(?:tcp|udp)\z/D', $name, $parts) !== 1) { return false; }
        $first = (int) ($parts[1] ?? 0); $last = (int) ($parts[2] ?? $first);
        if ($first > 65_535 || $last < $first || $last > 65_535) { return false; }
    }
    return server_cell_loopback_mapping(['3306/tcp' => $ports['3306/tcp']], $port);
}

function server_cell_loopback_mapping(mixed $ports, int $port): bool
{
    if ($port < 1 || $port > 65_535 || !is_array($ports) || count($ports) !== 1) { return false; }
    $bindings = is_array($ports['3306/tcp'] ?? null) ? $ports['3306/tcp'] : [];
    return array_is_list($bindings) && count($bindings) === 1 && server_cell_loopback_binding($bindings[0] ?? null, $port);
}

function server_cell_loopback_binding(mixed $binding, int $port): bool
{
    return is_array($binding) && count($binding) === 2 && ($binding['HostIp'] ?? null) === '127.0.0.1' && ($binding['HostPort'] ?? null) === (string) $port;
}

/** @return array{context: string, endpoint: string, platform: string, contextConfig: string, privateConfig: string} */
function server_cell_context_declaration(array $plan, string $platform): array
{
    $expected = match ($platform) {
        'Windows' => ['desktop-linux', 'npipe:////./pipe/dockerDesktopLinuxEngine'],
        'Linux' => ['default', 'unix:///var/run/docker.sock'],
        default => throw new RuntimeException('Supported local Docker platform required.'),
    };
    $private = SqlColumn::string($plan, 'runtime') . '/private/docker-config'; $source = SqlColumn::string($plan, 'dockerContextConfig');
    if ([$plan['dockerContext'] ?? null, $plan['dockerEndpoint'] ?? null, $plan['dockerPlatform'] ?? null, $plan['dockerConfig'] ?? null]
        !== [$expected[0], $expected[1], $platform, $private] || $source === '' || str_contains($source, "\0") || str_contains($source, '..')) {
        throw new RuntimeException('Explicit local Docker declaration refused.');
    }
    return ['context' => $expected[0], 'endpoint' => $expected[1], 'platform' => $platform, 'contextConfig' => $source, 'privateConfig' => $private];
}

/** Authored metadata and real metadata share this strict parser; only a native observation can admit an actual run.
 * @return array{context: string, endpoint: string, platform: string, contextConfig: string, privateConfig: string} */
function server_cell_context_metadata(array $plan, string $stdout, string $platform): array
{
    $selection = server_cell_context_declaration($plan, $platform);
    if (strlen($stdout) > 65_536) { throw new RuntimeException('Bounded local Docker metadata required.'); }
    try { $rows = JsonRecord::decode($stdout, 32); } catch (JsonException) { throw new RuntimeException('Selected local Docker metadata refused.'); }
    $record = JsonRecord::checked($rows[0] ?? null); $endpoints = JsonRecord::checked($record['Endpoints'] ?? null);
    $docker = JsonRecord::checked($endpoints['docker'] ?? null); $tls = JsonRecord::checked($record['TLSMaterial'] ?? null);
    if (count($rows) !== 1 || !array_is_list($rows) || count($endpoints) !== 1 || $tls !== []
        || [$record['Name'] ?? null, $docker['Host'] ?? null, $docker['SkipTLSVerify'] ?? null] !== [$selection['context'], $selection['endpoint'], false]) {
        throw new RuntimeException('Selected local Docker metadata refused.');
    }
    return $selection;
}

/** @return array{context: string, endpoint: string, platform: string, contextConfig: string, privateConfig: string} */
function server_cell_context_observation(array $plan, array $result, array $receipt, string $platform): array
{
    if ([$result['exit_code'] ?? null, $result['stderr'] ?? null, $receipt['exit_code'] ?? null, $receipt['closed'] ?? null,
        $receipt['root_exit_observed'] ?? null, $receipt['signaled'] ?? null, $receipt['term_signal'] ?? null] !== [0, '', 0, true, true, false, null]
        || SqlColumn::integer($receipt, 'pid') <= 0) { throw new RuntimeException('Actual local Docker metadata closure refused.'); }
    return server_cell_context_metadata($plan, SqlColumn::string($result, 'stdout'), $platform);
}

/** The selected context is read once, before the first daemon mutation; later commands use the admitted endpoint.
 * @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context */
function server_cell_admit_docker(array &$context): void
{
    $lease = server_cell_observer_begin(8, label: 'native/context-admission');
    server_cell_tool($context['plan']);
    if (($context['plan']['dockerAdmission'] ?? null) !== null) { throw new RuntimeException('Fresh Docker metadata admission required.'); }
    $selection = server_cell_context_declaration($context['plan'], PHP_OS_FAMILY); $tool = JsonRecord::checked($context['plan']['docker'] ?? null);
    $arguments = [SqlColumn::string($tool, 'path'), '--config', $selection['contextConfig'], '--context', $selection['context'], 'context', 'inspect', $selection['context']];
    $environment = server_cell_docker_environment($context);
    $result = Subprocess::run($arguments, $context['directory'], $environment, new CommandOptions(timeout: 8, replacementEnvironment: $environment));
    $receipt = SubprocessReceipt::read($result['files']['receipt']);
    $admission = server_cell_context_observation($context['plan'], $result, $receipt, PHP_OS_FAMILY)
        + ['metadataSha256' => hash('sha256', $result['stdout']), 'closureSha256' => RequiredFile::digest($result['files']['receipt'])];
    $context['plan']['dockerAdmission'] = $admission;
    server_cell_journal($context, stage: 'docker-context-admitted', facts: $admission);
    server_cell_observer_finish($lease, server_cell_observer_closed($result, $receipt));
}

/** @param array{plan: array, ...} $context
 * @param list<string> $arguments
 * @return list<string> */
function server_cell_docker_arguments(array $context, array $arguments, string $platform): array
{
    $selection = server_cell_context_declaration($context['plan'], $platform); $admitted = JsonRecord::checked($context['plan']['dockerAdmission'] ?? null);
    foreach ($selection as $key => $value) { if (($admitted[$key] ?? null) !== $value) { throw new RuntimeException('Exact admitted local Docker endpoint changed.'); } }
    foreach (['metadataSha256', 'closureSha256'] as $key) {
        if (preg_match('/\A[0-9a-f]{64}\z/D', SqlColumn::string($admitted, $key)) !== 1) { throw new RuntimeException('Native Docker admission evidence absent.'); }
    }
    $tool = JsonRecord::checked($context['plan']['docker'] ?? null);
    return [SqlColumn::string($tool, 'path'), '--config', $selection['privateConfig'], '--host', $selection['endpoint'], ...$arguments];
}

/** @param array{plan: array, environment: array<string, string>, ...} $context
 * @return array<string, string> */
function server_cell_docker_environment(array $context): array
{
    $environment = [];
    foreach ($context['environment'] as $name => $value) { if (str_starts_with(strtoupper($name), 'DOCKER_')) { continue; } $environment[$name] = $value; }
    $environment['DOCKER_CONFIG'] = SqlColumn::string($context['plan'], 'dockerConfig');
    return $environment;
}

/** @param array{plan: array, directory: TemporaryDirectory, environment: array<string, string>} $context
 * @return list<string> */
function server_cell_native_ready_command(array $context, string $engine, string $platform): array
{
    return server_cell_docker_arguments($context, server_cell_client_arguments($context, $engine), $platform);
}

/** No implicit observer is constructed by PHP. Only the already armed owner may admit work. */
function server_cell_observer_require(array $plan): void
{
    if (($plan['executionMode'] ?? null) !== 'qualified') { throw new RuntimeException('Qualified execution mode required.'); }
    $configuration = server_cell_observer_configuration(); $observer = JsonRecord::checked($plan['observer'] ?? null);
    $selected = server_cell_observer_declaration($plan, SqlColumn::string($configuration, 'phase'));
    if ([$configuration['cellNonce'] ?? null, $configuration['nonce'] ?? null, $configuration['host'] ?? null, $configuration['port'] ?? null,
        $configuration['authorityFile'] ?? null] !== [$plan['nonce'] ?? null, $selected['nonce'] ?? null, '127.0.0.1', $observer['port'] ?? null, $selected['authorityFile'] ?? null]) {
        throw new RuntimeException('Exact prestarted observer required.');
    }
    server_cell_observer_remaining();
}

function server_cell_observer_declaration(array $plan, string $kind): array
{
    $observer = JsonRecord::checked($plan['observer'] ?? null); $phases = JsonRecord::checked($observer['phases'] ?? null);
    foreach (array_keys($phases) as $index) {
        $phase = JsonRecord::checked($phases[$index] ?? null);
        if (($phase['kind'] ?? null) === $kind) { return $phase; }
    }
    throw new RuntimeException('Explicit frozen observer phase absent.');
}

function server_cell_observer_phase(string $expected): void
{
    if ((server_cell_observer_configuration()['phase'] ?? null) !== $expected) { throw new RuntimeException('Explicit observer phase required.'); }
}

function server_cell_observer_configuration(): array
{
    $file = getenv('TABLO_SERVER_OBSERVER_FILE'); $expected = getenv('TABLO_SERVER_OBSERVER_SHA256');
    if (!is_string($file) || !is_string($expected) || is_link($file) || !hash_equals($expected, RequiredFile::digest($file))) {
        throw new RuntimeException('Frozen observer admission absent.');
    }
    $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($file)), 16); $nonce = SqlColumn::string($record, 'nonce');
    $base = str_replace('\\', replace: '/', subject: dirname($file, levels: 2));
    if (($record['schema'] ?? null) !== 'tablo/server-observer-controller/v1' || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1
        || basename($base) !== 'tablo-observer-' . $nonce || $file !== $base . '/private/controller.json'
        || ($record['host'] ?? null) !== '127.0.0.1' || ($record['authorityFile'] ?? null) !== $base . '/private/controller.bin') {
        throw new RuntimeException('Owned local observer declaration refused.');
    }
    server_cell_observer_port($record);
    return $record;
}

function server_cell_observer_port(array $record): int
{
    $port = SqlColumn::integer($record, 'port');
    if ($port < 1024 || $port > 65_535 || $port === 8087) { throw new RuntimeException('Explicit local observer port refused.'); }
    return $port;
}

/** @return array{id: int, remainingNanoseconds: string} */
function server_cell_observer_begin(float $seconds, string $label): array
{
    $reply = server_cell_observer_request('begin', ['seconds' => $seconds, 'label' => $label]);
    return ['id' => SqlColumn::integer($reply, 'id'), 'remainingNanoseconds' => SqlColumn::string($reply, 'remainingNanoseconds')];
}

function server_cell_observer_finish(array $lease, bool $closed): void
{
    $reply = server_cell_observer_request('finish', ['id' => SqlColumn::integer($lease, 'id'), 'closureKnown' => $closed]);
    if (($reply['closed'] ?? null) !== true) { throw new RuntimeException('Observer closure acknowledgement absent.'); }
}

function server_cell_observer_remaining(): float
{
    $reply = server_cell_observer_request('remaining', []); $remaining = SqlColumn::string($reply, 'remainingNanoseconds');
    if (preg_match('/\A[1-9][0-9]{0,13}\z/D', $remaining) !== 1) { throw new RuntimeException('Observer parent budget absent.'); }
    return min(1_800.0, \Tablo\DatabaseInteger::checked($remaining) / 1_000_000_000);
}

/** @param Subprocess::Result $result */
function server_cell_observer_closed(array $result, array $receipt): bool
{
    return is_int($result['exit_code']) && ($receipt['exit_code'] ?? null) === $result['exit_code']
        && ($receipt['closed'] ?? null) === true && ($receipt['root_exit_observed'] ?? null) === true
        && ($receipt['signaled'] ?? null) === false && ($receipt['term_signal'] ?? null) === null
        && is_int($receipt['pid'] ?? null) && $receipt['pid'] > 0;
}

/** Each request is serialized across the owned PHP scope; reconnecting never resets replay state. */
function server_cell_observer_request(string $kind, array $payload): array
{
    $configuration = server_cell_observer_configuration(); $authority = SqlColumn::string($configuration, 'authorityFile');
    if ($kind === 'prepare-failure') { $payload = ['cellNonce' => SqlColumn::string($configuration, 'cellNonce'), ...$payload]; }
    if (is_link($authority)) { throw new RuntimeException('Linked observer authority refused.'); }
    $key = RequiredFile::bytes(file_get_contents($authority));
    if (strlen($key) !== 32) { throw new RuntimeException('Raw observer authority required.'); }
    $counter = fopen(dirname($authority) . '/controller.sequence', mode: 'r+b');
    if (!is_resource($counter)) { throw new RuntimeException('Owned observer replay counter absent.'); }
    try {
        if (!flock($counter, operation: LOCK_EX)) { throw new RuntimeException('Observer replay lock unavailable.'); }
        $previous = RequiredFile::bytes(stream_get_contents($counter, length: 16));
        if (preg_match('/\A(?:0|[1-9][0-9]{0,8})\n\z/D', $previous) !== 1) { throw new RuntimeException('Observer replay state refused.'); }
        $sequence = (int) $previous + 1; $payload = ['sequence' => $sequence, ...$payload];
        rewind($counter); $bytes = $sequence . "\n";
        if (!ftruncate($counter, size: 0) || fwrite($counter, $bytes) !== strlen($bytes) || !fflush($counter)) {
            throw new RuntimeException('Observer replay state write failed.');
        }
        return server_cell_observer_exchange($configuration, $key, $kind, $payload);
    } finally { flock($counter, operation: LOCK_UN); fclose($counter); }
}

/** The external owner remains armed while PHP connects, writes, reads, hashes and closes this socket. */
function server_cell_observer_exchange(array $configuration, #[\SensitiveParameter] string $key, string $kind, array $payload): array
{
    $nonce = SqlColumn::string($configuration, 'nonce');
    $encoded = base64_encode(\Tablo\JsonEncoding::encode($payload));
    $frame = \Tablo\JsonEncoding::encode(['v' => 1, 'nonce' => $nonce, 'seq' => 1, 'kind' => $kind, 'payload' => $encoded,
        'mac' => server_cell_observer_mac($key, direction: 'controller-to-owner', nonce: $nonce, kind: $kind, payload: $encoded)]) . "\n";
    if (strlen($frame) > 65_536) { throw new RuntimeException('Observer frame limit exceeded.'); }
    $number = 0; $message = '';
    $socket = stream_socket_client('tcp://127.0.0.1:' . SqlColumn::integer($configuration, 'port'), $number, $message, timeout: 1);
    if (!is_resource($socket)) { throw new RuntimeException('Prestarted local observer unavailable.'); }
    try {
        if (!stream_set_timeout($socket, seconds: 1)) { throw new RuntimeException('Observer socket deadline unavailable.'); }
        $offset = 0;
        while ($offset < strlen($frame)) {
            $written = fwrite($socket, substr($frame, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Observer frame write incomplete.'); }
            $offset += $written;
        }
        $reply = fgets($socket, length: 65_537);
        if (!is_string($reply) || !str_ends_with($reply, "\n") || str_contains($reply, "\r") || strlen($reply) > 65_536) {
            throw new RuntimeException('Observer response incomplete.');
        }
        return server_cell_observer_response($reply, $key, $nonce, SqlColumn::integer($payload, 'sequence'));
    } finally { fclose($socket); }
}

function server_cell_observer_mac(#[\SensitiveParameter] string $key, string $direction, string $nonce, string $kind, string $payload): string
{
    return hash_hmac('sha256', "tablo-i39-windows-adapter/1\n" . $direction . "\n" . $nonce . "\n1\n" . $kind . "\n" . $payload, $key);
}

function server_cell_observer_response(string $bytes, #[\SensitiveParameter] string $key, string $nonce, int $sequence): array
{
    $frame = JsonRecord::decode($bytes, 16); $keys = array_keys($frame); sort($keys, SORT_STRING);
    if ($keys !== ['kind', 'mac', 'nonce', 'payload', 'seq', 'v'] || [$frame['v'] ?? null, $frame['nonce'] ?? null, $frame['seq'] ?? null,
        $frame['kind'] ?? null] !== [1, $nonce, 1, 'accepted']) { throw new RuntimeException('Observer response schema refused.'); }
    $encoded = SqlColumn::string($frame, 'payload');
    if (!hash_equals(server_cell_observer_mac($key, direction: 'owner-to-controller', nonce: $nonce, kind: 'accepted', payload: $encoded), SqlColumn::string($frame, 'mac'))) {
        throw new RuntimeException('Observer response authentication refused.');
    }
    $decoded = base64_decode($encoded, strict: true);
    if (!is_string($decoded) || strlen($decoded) > 32_768 || base64_encode($decoded) !== $encoded) { throw new RuntimeException('Observer payload refused.'); }
    $reply = JsonRecord::decode($decoded, 16);
    if (($reply['sequence'] ?? null) !== $sequence) { throw new RuntimeException('Observer response replay refused.'); }
    return $reply;
}

/** Every started direct child needs a positive native receipt; no-start attempts remain a separate category.
 * @return array{starts: int, closes: int, nativeClosureKnown: true, nativeClosureScope: string, noStartAttempts: int, noStart: list<array{sourceReceipt: string, pid: ?int, exit_code: ?int, proc_close: ?int, root_exit_observed: bool, classification: string}>} */
function server_cell_observer_audit(string $directory): array
{
    $records = server_cell_observer_records($directory); $starts = $records['starts']; $closes = $records['closes'];
    $stopped = []; $noStart = [];
    foreach ($closes as $source => $receipt) {
        if ($receipt['pid'] !== null) { $stopped[$source] = $receipt['pid']; continue; }
        if (array_key_exists($source, $starts)) { throw new RuntimeException('No-start receipt contradicts a registered child.'); }
        $noStart[] = ['sourceReceipt' => $source, 'pid' => $receipt['pid'], 'exit_code' => $receipt['exit_code'],
            'proc_close' => $receipt['proc_close'], 'root_exit_observed' => $receipt['root_exit_observed'],
            'classification' => 'closed reported no-process-capability attempt; native exit not inferred'];
    }
    ksort($starts, SORT_STRING); ksort($stopped, SORT_STRING);
    if ($starts === [] || $starts !== $stopped) { throw new RuntimeException('Observer nested start/close union incomplete.'); }
    return ['starts' => count($starts), 'closes' => count($stopped), 'nativeClosureKnown' => true,
        'nativeClosureScope' => 'positively registered started children only', 'noStartAttempts' => count($noStart), 'noStart' => $noStart];
}

/** @return array{starts: array<string, int>, closes: array<string, array{pid: ?int, exit_code: ?int, proc_close: ?int, root_exit_observed: bool}>} */
function server_cell_observer_records(string $directory): array
{
    if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('Observer closure directory refused.'); }
    $starts = []; $closes = [];
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isLink() || !$entry->isFile()) { throw new RuntimeException('Observer closure evidence is not regular.'); }
        $name = $entry->getFilename();
        if (!str_starts_with($name, 'direct-child-start-') && !str_starts_with($name, 'direct-child-close-')) { continue; }
        $record = JsonRecord::decode(RequiredFile::bytes(file_get_contents($entry->getPathname())));
        $source = server_cell_observer_source($record, $directory);
        if (str_starts_with($name, 'direct-child-start-')) {
            if (array_key_exists($source, $starts) || SqlColumn::integer($record, 'pid') < 1) { throw new RuntimeException('Repeated or invalid observer child start.'); }
            $starts[$source] = SqlColumn::integer($record, 'pid'); continue;
        }
        if (array_key_exists($source, $closes)) { throw new RuntimeException('Repeated observer child close.'); }
        $closes[$source] = server_cell_observer_receipt($record, $directory);
    }
    return ['starts' => $starts, 'closes' => $closes];
}

/** Source paths are identifiers for removed fixtures; only the retained sink copies are read. */
function server_cell_observer_source(array $record, string $directory): string
{
    $source = SqlColumn::string($record, 'sourceReceipt'); $normalized = str_replace('\\', replace: '/', subject: $source);
    $prefix = str_replace('\\', replace: '/', subject: dirname($directory, levels: 2)) . '/tmp/';
    if (!str_starts_with($normalized, $prefix) || str_contains($normalized, '/../') || str_contains($normalized, '/./')
        || preg_match('~/command-[0-9a-f]{24}\.json\z~D', $normalized) !== 1) { throw new RuntimeException('Observer receipt source identity refused.'); }
    return $source;
}

/** @return array{pid: ?int, exit_code: ?int, proc_close: ?int, root_exit_observed: bool} */
function server_cell_observer_receipt(array $record, string $directory): array
{
    require_once __DIR__ . '/ServerFixtureCatalog.php';
    $source = server_cell_observer_source($record, $directory); $copy = SqlColumn::string($record, 'receiptCopy');
    if ($copy !== $directory . '/direct-child-' . hash('sha256', $source) . '.receipt' || is_link($copy) || !is_file($copy)
        || !hash_equals(SqlColumn::string($record, 'receiptSha256'), RequiredFile::digest($copy))) { throw new RuntimeException('Exact observer closure copy refused.'); }
    $receipt = SubprocessReceipt::read($copy);
    if (!array_key_exists('pid', $record) || $receipt['pid'] !== $record['pid']) { throw new RuntimeException('Observer child PID identity refused.'); }
    foreach (['stdout' => '.out', 'stderr' => '.err'] as $name => $suffix) {
        $stream = JsonRecord::checked($receipt['streams'][$name] ?? null);
        if (SqlColumn::string($stream, 'path') !== substr($source, offset: 0, length: -5) . $suffix) {
            throw new RuntimeException('Observer original stream source identity refused.');
        }
    }
    server_cell_observer_streams($record, $receipt, $copy);
    if ($receipt['pid'] === null) { server_cell_observer_no_start($receipt); }
    if ($receipt['pid'] !== null && (!\Tablo\Tools\server_fixture_catalog_direct_stopped($receipt) || $receipt['pid'] < 1)) {
        throw new RuntimeException('Observer nested native closure unknown.');
    }
    return ['pid' => $receipt['pid'], 'exit_code' => $receipt['exit_code'], 'proc_close' => $receipt['proc_close'] ?? null,
        'root_exit_observed' => $receipt['root_exit_observed']];
}

/** A closed failed-spawn report is not a native child exit, and never supplies a process capability. */
function server_cell_observer_no_start(array $receipt): void
{
    server_cell_observer_no_start_state($receipt);
    $events = JsonRecord::checked($receipt['events'] ?? null);
    if (!array_is_list($events) || count($events) !== 3) { throw new RuntimeException('No-start lifecycle refused.'); }
    $previous = null; $monotonicCount = 0;
    foreach (array_keys($events) as $index) {
        $current = server_cell_observer_no_start_event(JsonRecord::checked($events[$index] ?? null), $index);
        if ($current === null) { continue; }
        if ($previous !== null && $current < $previous) { throw new RuntimeException('No-start monotonic evidence refused.'); }
        $previous = $current; ++$monotonicCount;
    }
    if (!in_array($monotonicCount, [0, 3], strict: true)) { throw new RuntimeException('Partial no-start monotonic evidence refused.'); }
}

function server_cell_observer_no_start_state(array $receipt): void
{
    foreach (['pid', 'exit_code', 'proc_close', 'term_signal'] as $field) {
        if (!array_key_exists($field, $receipt) || $receipt[$field] !== null) { throw new RuntimeException('No-start native facts refused.'); }
    }
    if (($receipt['closed'] ?? null) !== true || ($receipt['root_exit_observed'] ?? null) !== false || ($receipt['signaled'] ?? null) !== false
        || (array_key_exists('raw_status', $receipt) && $receipt['raw_status'] !== null)) { throw new RuntimeException('No-start state refused.'); }
}

function server_cell_observer_no_start_event(array $event, int $index): ?int
{
    server_cell_observer_no_start_shape($event, $index);
    if ($index === 0) {
        $arguments = SqlColumn::integer($event, 'argument_count');
        if ($arguments < 0 || $arguments > 64) { throw new RuntimeException('No-start argument count refused.'); }
    }
    if ($index === 1 && !in_array($event['type'] ?? null, ['RuntimeException', 'ValueError'], strict: true)) { throw new RuntimeException('No-start exception type refused.'); }
    if (!array_key_exists('monotonic_ns', $event)) { return null; }
    $current = SqlColumn::integer($event, 'monotonic_ns');
    if ($current < 1) { throw new RuntimeException('No-start monotonic evidence refused.'); }
    return $current;
}

function server_cell_observer_no_start_shape(array $event, int $index): void
{
    $stage = $index === 2 ? 'closed' : 'spawn';
    $fields = match ($index) { 0 => ['utc', 'stage', 'argument_count'], 1 => ['utc', 'stage', 'type'], default => ['utc', 'stage'] };
    if (($event['stage'] ?? null) !== $stage || array_diff(array_keys($event), [...$fields, 'monotonic_ns']) !== []
        || array_diff($fields, array_keys($event)) !== [] || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D', SqlColumn::string($event, 'utc')) !== 1) {
        throw new RuntimeException('No-start event refused.');
    }
}

function server_cell_observer_streams(array $record, array $receipt, string $copy): void
{
    $streams = JsonRecord::checked($record['streams'] ?? null); $expected = JsonRecord::checked($receipt['streams'] ?? null);
    foreach (['stdout', 'stderr'] as $name) {
        $stream = JsonRecord::checked($streams[$name] ?? null); $native = JsonRecord::checked($expected[$name] ?? null);
        $path = SqlColumn::string($stream, 'path');
        if ($path !== $copy . '.' . $name || is_link($path) || !is_file($path) || SqlColumn::string($stream, 'sha256') !== SqlColumn::string($native, 'sha256')
            || !hash_equals(SqlColumn::string($stream, 'sha256'), RequiredFile::digest($path))) { throw new RuntimeException('Observer closed native stream custody changed.'); }
    }
}
