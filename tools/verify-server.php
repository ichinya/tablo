<?php
declare(strict_types=1);

use Tablo\JsonRecord;
use Tablo\SqlColumn;
use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;

require_once dirname(__DIR__) . '/app/RuntimeIni.php';
\Tablo\RuntimeIni::set('display_errors', '0'); \Tablo\RuntimeIni::set('log_errors', '0');
set_error_handler(static function (int $level): bool {
    if (!(error_reporting() & $level)) { return true; }
    throw new RuntimeException('Server verification operation failed.');
});

/** @param list<string> $arguments
 * @return array{engine: string, lanes: list<string>} */
function server_verify_options(array $arguments): array
{
    $engine = array_shift($arguments);
    if (!in_array($engine, ['--engine=mysql', '--engine=mariadb'], strict: true)) { throw new RuntimeException('Required selected engine invalid.'); }
    if ($arguments !== [] && $arguments !== ['--cross']) { throw new RuntimeException('Required native lane invalid.'); }
    if ($arguments !== [] && [$engine, PHP_MAJOR_VERSION, PHP_MINOR_VERSION] !== ['--engine=mysql', 8, 5]) {
        throw new RuntimeException('Required main-plus-cross cell is MySQL/PHP8.5 only.');
    }
    return ['engine' => substr($engine, offset: 9), 'lanes' => $arguments === [] ? ['main'] : ['main', 'cross']];
}

function server_verify_manifest(string $root): array
{
    $map = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/tools/server-native-tests.json')), 32);
    if ([$map['schema'] ?? null, $map['sourceState'] ?? null] !== ['tablo/server-native-tests/v1', 'complete']) {
        throw new RuntimeException('Server source declaration incomplete or unsupported.');
    }
    $limits = ['catalogsPerCell' => 64, 'connections' => 8, 'children' => 11, 'nativeCaseSeconds' => 8,
        'httpCaseSeconds' => 20, 'nonzeroAssertionsRequired' => true, 'allCasesMustPass' => true];
    if (($map['limits'] ?? null) !== $limits || ($map['engines'] ?? null) !== ['mysql', 'mariadb']) {
        throw new RuntimeException('Server limits or selected engine set changed.');
    }
    return $map;
}

/** @return list<string> */
function server_verify_strings(mixed $input): array
{
    $values = JsonRecord::checked($input);
    if (!array_is_list($values)) { throw new RuntimeException('Required selector list malformed.'); }
    $strings = [];
    foreach (array_keys($values) as $index) { $strings[] = SqlColumn::string($values, (string) $index); }
    return $strings;
}

function server_verify_families(string $root, array $map): void
{
    $families = JsonRecord::checked($map['requiredFamilies'] ?? null); $names = [];
    foreach (array_keys($families) as $index) {
        $record = JsonRecord::checked($families[$index] ?? null); $names[] = SqlColumn::string($record, 'name');
        $paths = server_verify_strings($record['paths'] ?? null);
        if ($paths === []) { throw new RuntimeException('Required engine family has no selector.'); }
        foreach ($paths as $path) {
            if (!str_starts_with($path, 'tests/Server') || str_contains($path, '..') || !is_file($root . '/' . $path)) {
                throw new RuntimeException('Required engine family is not implemented.');
            }
        }
    }
    if ($names !== ['Flow', 'Configuration', 'Constraints', 'LoginRace', 'SettlementRace', 'SettlementFailure', 'Worker', 'History',
        'NotificationRace', 'ReadApi', 'Key', 'Transfer', 'MigrationFresh', 'MigrationPrevious', 'MigrationAdmission',
        'MigrationFailure', 'MigrationCrash', 'MigrationRace', 'TransactionFailure', 'Watermark', 'CrossTransfer']) {
        throw new RuntimeException('Required D0 family set changed.');
    }
}

/** @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases */
function server_verify_bindings(string $root, array $map, array $cases, string $lane): void
{
    $declaration = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/contracts/php-bindings.json')), 64);
    $symbols = JsonRecord::checked($declaration['symbols'] ?? null); $expected = [];
    foreach (array_keys($symbols) as $index) {
        $symbol = JsonRecord::checked($symbols[$index] ?? null);
        $expected[SqlColumn::string($symbol, 'id')] = SqlColumn::string($symbol, 'kind');
    }
    $bindings = JsonRecord::checked($map['bindings'] ?? null);
    if (count($expected) !== 53 || array_diff_key($bindings, $expected) !== [] || array_diff_key($expected, $bindings) !== []) {
        throw new RuntimeException('Exact53 native binding consumer set changed.');
    }
    foreach ($expected as $id => $kind) {
        $record = JsonRecord::checked($bindings[$id] ?? null);
        if (SqlColumn::string($record, 'kind') !== $kind) { throw new RuntimeException('Native binding kind changed.'); }
        server_verify_binding_paths(server_verify_strings($record[$lane] ?? null), $cases, $lane);
    }
}

/** @param list<string> $paths
 * @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases */
function server_verify_binding_paths(array $paths, array $cases, string $lane): void
{
    if ($lane === 'main' && $paths === []) { throw new RuntimeException('Affected native binding has no server consumer.'); }
    foreach ($paths as $path) {
        if (!in_array($path, array_column($cases, 'path'), strict: true)) { throw new RuntimeException('Native binding selector has no required method.'); }
    }
}

/** The wrapper runs only from a fresh private source copy, before reading its authored empty .env. */
function server_verify_runtime(string $root): string
{
    $nonce = getenv('TABLO_TEST_RUN_NONCE'); $configured = getenv('TABLO_TEST_SOURCE_ROOT');
    if (!is_string($nonce) || preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || !is_string($configured)) {
        throw new RuntimeException('Owned server runtime declaration absent.');
    }
    $normalize = static fn (string $value): string => str_replace('\\', replace: '/', subject: $value);
    $main = dirname($root);
    if ($normalize($root) !== $normalize($configured) || basename($root) !== 'source' || basename($main) !== 'tablo-server-' . $nonce) {
        throw new RuntimeException('Fresh copied-source ownership refused.');
    }
    if (file_get_contents($root . '/.env') !== '' || $normalize(sys_get_temp_dir()) !== $normalize($main . '/tmp')) {
        throw new RuntimeException('Private fixture environment admission refused.');
    }
    return $main;
}

/** @return array<string, string> */
function server_verify_environment(): array
{
    $environment = [];
    foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'PATH', 'Path', 'PATHEXT', 'COMSPEC', 'USERNAME', 'USERPROFILE', 'LOCALAPPDATA',
        'APPDATA', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramData', 'TEMP', 'TMP', 'TMPDIR', 'PHPRC', 'PHP_INI_SCAN_DIR',
        'LEKALO_BIN', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'TABLO_TEST_RUNTIME_ROOT', 'TABLO_TEST_RUN_NONCE', 'TABLO_TEST_SOURCE_ROOT',
        'TABLO_SESSION_EVIDENCE_DIRECTORY', 'TABLO_TEST_CROSS_RUNTIME_ROOT', 'TABLO_TEST_CELL_PLAN', 'TABLO_TEST_CELL_PLAN_SHA256',
        'TABLO_SERVER_OBSERVER_FILE', 'TABLO_SERVER_OBSERVER_SHA256', 'TABLO_TEST_BASELINE_DIRECTORY',
        'TABLO_TEST_BASELINE_PHASE_FILE', 'TABLO_TEST_BASELINE_PHASE_SHA256'] as $name) {
        $value = getenv($name);
        if (is_string($value)) { $environment[$name] = $value; }
    }
    return $environment + ['TABLO_DB_DRIVER' => 'sqlite', 'TABLO_DB' => ':memory:', 'TABLO_TOKEN_KEY_FILE' => '',
        'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_PROCESS_TIMEOUT' => '1800'];
}

/** @return list<string> */
function server_verify_composer(): array
{
    $path = getenv('TABLO_TEST_COMPOSER_PHAR'); $expected = getenv('TABLO_TEST_COMPOSER_SHA256');
    if (!is_string($path) || !is_string($expected) || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1
        || !hash_equals($expected, \Tablo\RequiredFile::digest($path))) { throw new RuntimeException('Explicit attested Composer executable unavailable.'); }
    return [PHP_BINARY, $path, '--no-interaction', '--no-plugins', 'verify'];
}

function server_verify_readiness(EngineFixture $fixture, string $engine): array
{
    $environment = $fixture->environment();
    if ([$environment['TABLO_DB_HOST'] ?? null, $environment['TABLO_DB_TLS'] ?? null] !== ['127.0.0.1', 'verify']) {
        throw new RuntimeException('Loopback verified-TLS fixture required.');
    }
    $db = $fixture->database();
    if (\Tablo\DatabaseSession::get($db)?->configuration->engine !== $engine) { throw new RuntimeException('Actual selected engine mismatch.'); }
    $facts = \Tablo\ServerSchema::rows($db, 'SELECT VERSION() AS version,DATABASE() AS catalog,CONNECTION_ID() AS connection_id');
    $ssl = \Tablo\ServerSchema::rows($db, "SHOW SESSION STATUS LIKE 'Ssl_cipher'");
    $cipher = SqlColumn::string($ssl[0] ?? [], 'Value');
    if ($cipher === '') { throw new RuntimeException('Actual TLS negotiation absent.'); }
    return ['engine' => $engine, 'observed' => $facts, 'tlsCipher' => $cipher,
        'client' => $db->getAttribute(PDO::ATTR_CLIENT_VERSION), 'server' => $db->getAttribute(PDO::ATTR_SERVER_VERSION)];
}

/** @param list<string> $command
 * @param array<string, string> $environment
 * @param (\Closure(Subprocess::Result): void)|null $failed
 * @return \Tablo\Tests\Support\Subprocess::Result */
function server_verify_child(array $command, TemporaryDirectory $directory, array $environment, float $timeout, ?\Closure $failed = null): array
{
    $result = Subprocess::run($command, $directory, $environment, new CommandOptions(timeout: $timeout, replacementEnvironment: $environment));
    if ($result['exit_code'] !== 0) {
        try { $failed?->__invoke($result); } catch (Throwable $discarded) { unset($discarded); }
        throw new RuntimeException('Required native child failed or its exit is unknown.');
    }
    return $result;
}

/**
 * @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases
 * @param array{context: array{plan: array, directory: TemporaryDirectory, environment: array<string, string>}, catalogs: array<string, \Tablo\Tools\ServerFixtureCatalog>, publicSources: list<string>} $owner
 */
function server_verify_lane(string $root, TemporaryDirectory $directory, array $cases, array $owner, array $selection): array
{
    $context = $owner['context']; $catalogs = $owner['catalogs'];
    $lane = SqlColumn::string($selection, 'lane'); $engine = SqlColumn::string($selection, 'engine');
    $map = JsonRecord::checked($selection['map'] ?? null);
    $results = []; $index = 0; $readiness = [];
    foreach ($cases as $identity => $spec) {
        $declaration = server_verify_fixture_declaration($map, $identity, $lane);
        $prepared = server_cell_prepare($context, $catalogs, $declaration, ['engine' => $engine, 'lane' => $lane, 'index' => $index]);
        if ($readiness === []) { $readiness = server_verify_prepared_readiness($prepared, $engine, JsonRecord::checked($context['plan']['services'] ?? null)); }
        $results[$identity] = server_verify_one_case($root, $directory, $spec,
            ['lane' => $lane, 'index' => $index++, 'identity' => $identity, 'catalogs' => $catalogs, 'publicSources' => $owner['publicSources']], $prepared);
    }
    if ($readiness === []) { throw new RuntimeException('Actual selected-engine readiness evidence absent.'); }
    return ['cases' => $results, 'readiness' => $readiness];
}

/** @param array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int} $spec
 * @param array{lane: string, index: int, identity: string, catalogs: array<string, \Tablo\Tools\ServerFixtureCatalog>, publicSources: list<string>} $selection
 * @param array{environment: array<string, string>, closureDirectory: string, fixtures: list<\Tablo\Tools\ServerFixturePlan::Fixture>} $prepared */
function server_verify_one_case(string $root, TemporaryDirectory $directory, array $spec, array $selection, array $prepared): array
{
    $lane = $selection['lane']; $index = $selection['index']; $identity = $selection['identity'];
    $maximum = $spec['deadlineSeconds'] ?? throw new RuntimeException('Required server whole-case deadline absent.');
    $lease = server_cell_observer_begin($maximum, 'case/' . $lane . '/' . $index);
    $report = $directory->path . '/native-' . $lane . '-' . $index . '.xml';
    $command = server_verify_command($spec['path'], $identity, $lane, $report);
    server_verify_progress($root, $lane, $index + 1, ['identity' => $identity, 'source' => $spec['path'], 'status' => 'started', 'deadlineSeconds' => $maximum]);
    $child = server_verify_child($command, $directory, $prepared['environment'], $maximum, server_verify_failure_callback($root, $selection, $spec));
    $native = \Tablo\Tools\server_native_junit($root, $report, [$identity => $spec]);
    \Tablo\Tools\server_native_summary(JsonRecord::decode($child['stdout'], 32), required: 1, assertions: $native['assertions']);
    foreach ($selection['catalogs'] as $catalog) { $catalog->complete($child, $native, $prepared['closureDirectory']); }
    server_verify_progress($root, $lane, $index + 1, ['identity' => $identity, 'source' => $spec['path'], 'status' => 'passed', 'native' => $native]);
    $result = ['source' => $spec['path'], 'native' => $native, 'junit' => $report, 'capture' => $child['files'],
        'fixtureSlots' => $prepared['fixtures'], 'descendantClosureDirectory' => $prepared['closureDirectory']];
    server_cell_observer_finish($lease, closed: true);
    return $result;
}

/** @param array{lane: string, index: int, identity: string, publicSources: list<string>, ...} $selection
 * @param array{path: string, ...} $spec
 * @return \Closure(array): void */
function server_verify_failure_callback(string $root, array $selection, array $spec): \Closure
{
    return static function (array $failed) use ($root, $selection, $spec): void {
        /** @var mixed $exit Only the bounded native nonzero integer is retained below. */
        $exit = $failed['exit_code'] ?? null;
        $bytes = SqlColumn::string($failed, 'stdout');
        $record = ['identity' => $selection['identity'], 'source' => $spec['path'], 'status' => 'failed',
            'childExit' => is_int($exit) && $exit >= 1 && $exit <= 255 ? $exit : 'UNKNOWN',
            'diagnostic' => \Tablo\Tools\server_native_failure($root, $bytes, $selection['identity'], $selection['publicSources'])];
        $memory = \Tablo\Tools\server_native_memory_failure($root, $bytes, $selection['identity'], $selection['publicSources']);
        if ($memory !== null) { $record['memoryDiagnostic'] = $memory; }
        server_verify_progress($root, $selection['lane'], $selection['index'] + 1, $record);
    };
}

function server_verify_progress(string $root, string $lane, int $index, array $record): void
{
    $status = SqlColumn::string($record, 'status');
    server_verify_retain(dirname($root) . '/evidence/native-' . $lane . '-' . $index . '-' . $status . '.json',
        ['schema' => 'tablo/server-native-progress/v1', 'nonce' => getenv('TABLO_TEST_RUN_NONCE'), 'case' => $record]);
}

/** Every lane shares the same once-created accounts and slot state; a failed lane cannot be retried as fresh.
 * @param array{context: array{plan: array, directory: TemporaryDirectory, environment: array<string, string>}, catalogs: array<string, \Tablo\Tools\ServerFixtureCatalog>, publicSources: list<string>} $owner
 * @param array{engine: string, lanes: list<string>} $selection */
function server_verify_lanes(string $root, TemporaryDirectory $directory, array $map, array $owner, array $selection): array
{
    $engine = $selection['engine']; $results = [];
    foreach ($selection['lanes'] as $lane) {
        $cases = \Tablo\Tools\server_native_cases($map, $lane);
        $actual = server_verify_lane($root, $directory, $cases, $owner, ['engine' => $engine, 'lane' => $lane, 'map' => $map]);
        $native = JsonRecord::checked($actual['cases'] ?? null);
        $results[$lane] = $actual + ['bindings' => server_verify_binding_evidence($root, $map, $native, $lane)];
    }
    $slots = [];
    foreach ($owner['catalogs'] as $name => $catalog) { $slots[$name] = $catalog->snapshot(); }
    return ['lanes' => $results, 'catalogStates' => $slots];
}

/** @return list<string> */
function server_verify_command(string $path, string $identity, string $lane, string $report): array
{
    $suite = $lane === 'cross' ? 'ServerCross' : 'Server';
    return [PHP_BINARY, 'vendor/bin/testo', 'run', '--config=testo-server.php', '--suite=' . $suite,
        '--path=' . $path, '--filter=' . $identity, '--json', '--log-junit=' . $report, '--no-ansi'];
}

/** @param array{environment: array<string, string>, closureDirectory: string, fixtures: list<\Tablo\Tools\ServerFixturePlan::Fixture>} $prepared */
function server_verify_prepared_readiness(array $prepared, string $engine, array $services): array
{
    foreach ($prepared['fixtures'] as $spec) {
        if ([$spec['engine'], $spec['initial']] !== [$engine, 'ready']) { continue; }
        $lease = server_cell_observer_begin(30, label: 'fixture/authenticated-pdo');
        $readiness = server_cell_with_environment($prepared['environment'], static function () use ($spec, $engine): array {
            $fixture = new EngineFixture($spec['alias']);
            try { return server_verify_readiness($fixture, $engine); } finally { $fixture->close(); }
        }, $services);
        server_cell_observer_finish($lease, closed: true);
        return $readiness;
    }
    return [];
}

function server_verify_fixture_declaration(array $map, string $identity, string $lane): array
{
    $lanes = JsonRecord::checked($map['cases'] ?? null); $records = JsonRecord::checked($lanes[$lane] ?? null);
    foreach (array_keys($records) as $index) {
        $record = JsonRecord::checked($records[$index] ?? null);
        if (($record['identity'] ?? null) === $identity) { return $record; }
    }
    throw new RuntimeException('Exact native fixture declaration absent.');
}

/** Prior exact Composer proof is imported; this function never invokes the baseline again. */
function server_verify_baseline(string $root, array $map, array $plan): array
{
    $declaration = JsonRecord::checked($map['baseline'] ?? null);
    if (SqlColumn::integer($declaration, 'requiredIdentityCount') !== 441) { throw new RuntimeException('Required SQLite baseline changed.'); }
    $policy = ['scope' => 'original-subprocess', 'defaultChildSeconds' => 8,
        'explicitBudgetSource' => 'Unchanged original CommandOptions call sites, including20second helper operations',
        'methodDeadlineSeconds' => null, 'overallParentSeconds' => 1800];
    if (($declaration['budgetPolicy'] ?? null) !== $policy) { throw new RuntimeException('Original SQLite budget policy changed.'); }
    if (($declaration['platformPolicy'] ?? null) !== \Tablo\Tools\server_native_baseline_platform_policy()) { throw new RuntimeException('Original SQLite platform policy changed.'); }
    $cases = \Tablo\Tools\server_native_cases(['cases' => ['baseline' => $declaration['identities'] ?? null]], lane: 'baseline');
    if (count($cases) !== 441) { throw new RuntimeException('Exact441 baseline declarations missing.'); }
    $phase = server_verify_baseline_phase($plan); $output = SqlColumn::string($phase, 'baselineDirectory');
    $child = \Tablo\Tools\server_verify_native_import($root, $output);
    $report = SqlColumn::string($child, 'report');
    $native = \Tablo\Tools\server_native_junit($root, $report, $cases);
    \Tablo\Tools\server_native_baseline_summary(JsonRecord::decode(SqlColumn::string($child, 'stdout'), 32), $native);
    return ['composerPhase' => $phase, 'native' => $native, 'junit' => $report,
        'capture' => $child['files'] ?? null, 'pid' => SqlColumn::integer($child, 'pid'),
        'provenance' => $child['provenance'] ?? null, 'manifest' => SqlColumn::string($child, 'manifest')];
}

/** Imported only after actual Composer/controller/adapter closure; no rerun or synthetic proc_close. */
function server_verify_baseline_phase(array $plan): array
{
    $baseline = server_cell_observer_declaration($plan, kind: 'baseline');
    $path = getenv('TABLO_TEST_BASELINE_PHASE_FILE'); $expected = getenv('TABLO_TEST_BASELINE_PHASE_SHA256');
    if (!is_string($path) || !is_string($expected) || $path !== SqlColumn::string($baseline, 'root') . '/evidence/phase-result.json'
        || is_link($path) || !hash_equals($expected, \Tablo\RequiredFile::digest($path))) { throw new RuntimeException('Actual Composer phase proof absent.'); }
    $record = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), 32);
    server_verify_baseline_phase_closed($record, $plan, $baseline);
    $custody = JsonRecord::checked($record['custody'] ?? null); $directory = SqlColumn::string($custody, 'baselineDirectory');
    $declared = SqlColumn::string($plan, 'runtime') . '/tmp/tablo-server-verify-' . substr(SqlColumn::string($baseline, 'nonce'), offset: 0, length: 16) . '/composer-verify';
    if ($directory !== $declared || getenv('TABLO_TEST_BASELINE_DIRECTORY') !== $directory
        || ($custody['nativeExport'] ?? null) !== $directory . '/native.json'
        || !hash_equals(SqlColumn::string($custody, 'nativeExportSha256'), \Tablo\RequiredFile::digest($directory . '/native.json'))) {
        throw new RuntimeException('Same Composer native export changed.');
    }
    return $custody + ['phaseProof' => $path, 'phaseProofSha256' => $expected];
}

function server_verify_baseline_phase_closed(array $record, array $plan, array $baseline): void
{
    $close = JsonRecord::checked($record['adapterClose'] ?? null);
    if ([SqlColumn::string($record, 'schema'), SqlColumn::string($record, 'status'), SqlColumn::string($record, 'kind'),
        SqlColumn::string($record, 'cellNonce'), SqlColumn::string($record, 'phaseNonce'), SqlColumn::integer($close, 'code'),
        SqlColumn::nullableString($close, 'signal'), $close['closeObserved'] ?? null]
        !== ['tablo/server-observer-phase/v1', 'passed', 'baseline', SqlColumn::string($plan, 'nonce'), SqlColumn::string($baseline, 'nonce'), 0, null, true]) {
        throw new RuntimeException('Composer phase native closure unknown.');
    }
    $custody = JsonRecord::checked($record['custody'] ?? null); $native = JsonRecord::checked($custody['custody'] ?? null);
    $controller = JsonRecord::checked($native['controller'] ?? null);
    $exit = PHP_OS_FAMILY === 'Windows' ? 'windowsExitUnsigned' : 'exitCode';
    if (($controller['rootExitObserved'] ?? null) !== true || ($controller[$exit] ?? null) !== 0 || SqlColumn::nullableString($controller, 'posixSignal') !== null) {
        throw new RuntimeException('Actual Composer controller native exit unknown.');
    }
}

/** @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases */
function server_verify_discovery(string $root, array $cases, string $lane): void
{
    /** @var mixed $configuration The source configuration must return the checked pinned API object. */
    $configuration = require $root . '/testo-server.php';
    if (!$configuration instanceof \Testo\Application\Config\ApplicationConfig) { throw new RuntimeException('Native suite configuration refused.'); }
    $container = \Testo\Application\Application::createFromConfig($configuration)->getContainer();
    server_verify_plugins($container, $configuration->plugins, scope: 'application');
    $name = $lane === 'cross' ? 'ServerCross' : 'Server'; $actual = [];
    foreach ($configuration->suites as $suite) {
        if ($suite->name !== $name) { continue; }
        server_verify_plugins($container, $suite->plugins, scope: 'suite');
        $located = $container->make(\Testo\Application\Internal\SuiteFactory::class)->create($suite);
        if ($located === null) { throw new RuntimeException('Required actual native suite missing.'); }
        $actual = [...$actual, ...server_verify_discovered_ids($located)];
    }
    sort($actual, SORT_STRING); $expected = array_keys($cases); sort($expected, SORT_STRING);
    if ($actual !== $expected) { throw new RuntimeException('Native method inventory differs; refusing a narrowed selector union.'); }
}

/** Uses the same pinned default/plugin resolution as Testo Application, without running a test body.
 * @param iterable<\Testo\Common\PluginConfigurator> $plugins */
function server_verify_plugins(\Internal\Container\Container $container, iterable $plugins, string $scope): void
{
    if ($plugins instanceof \Testo\Application\Config\Plugin\PluginCollection) {
        foreach ($plugins as $plugin) { $plugin->configure($container); }
        return;
    }
    $resolved = match ($scope) {
        'application' => \Testo\Application\Config\Plugin\ApplicationPlugins::with(...$plugins),
        'suite' => \Testo\Application\Config\Plugin\SuitePlugins::with(...$plugins),
        default => throw new RuntimeException('Native plugin scope refused.'),
    };
    foreach ($resolved as $plugin) { $plugin->configure($container); }
}

/** @return list<string> */
function server_verify_discovered_ids(\Testo\Core\Context\SuiteInfo $suite): array
{
    $identities = [];
    foreach ($suite->testCases->getCases() as $case) {
        if ($case->type !== 'test') { throw new RuntimeException('Unreviewed native test kind.'); }
        foreach ($case->tests->getTests(active: null) as $definition) {
            $reflection = $definition->reflection;
            $identities[] = $case->reflection === null ? $reflection->getName() : $case->reflection->getName() . '::' . $reflection->getName();
        }
    }
    return $identities;
}

function server_verify_retain(string $output, array $record): void
{
    $handle = fopen($output, mode: 'xb');
    if (!is_resource($handle)) { throw new RuntimeException('Cannot reserve native engine evidence.'); }
    try {
        $bytes = \Tablo\JsonEncoding::encode($record, JSON_PRETTY_PRINT);
        if (!chmod($output, permissions: 0o600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Cannot retain native engine evidence.');
        }
    } finally { fclose($handle); }
}

function server_verify_binding_evidence(string $root, array $map, array $results, string $lane): array
{
    $source = JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/contracts/php-bindings.json')), 64);
    $symbols = JsonRecord::checked($source['symbols'] ?? null); $bindings = JsonRecord::checked($map['bindings'] ?? null); $evidence = [];
    foreach (array_keys($symbols) as $index) {
        $symbol = JsonRecord::checked($symbols[$index] ?? null); $id = SqlColumn::string($symbol, 'id');
        $owner = JsonRecord::checked($symbol['source'] ?? null); $path = SqlColumn::string($owner, 'path');
        $record = JsonRecord::checked($bindings[$id] ?? null);
        $selectors = server_verify_strings($record[$lane] ?? null);
        $evidence[$id] = ['source' => $path, 'sourceSha256' => \Tablo\RequiredFile::digest($root . '/' . $path),
            'selectors' => $selectors, 'nativeEvidence' => server_verify_consumed_cases($results, $selectors)];
    }
    return $evidence;
}

/** @param list<string> $selectors
 * @return list<string> */
function server_verify_consumed_cases(array $results, array $selectors): array
{
    $identities = [];
    foreach (array_keys($results) as $identity) {
        $record = JsonRecord::checked($results[$identity] ?? null);
        if (!in_array(SqlColumn::string($record, 'source'), $selectors, strict: true)) { continue; }
        $identities[] = (string) $identity;
    }
    if ($selectors !== [] && $identities === []) { throw new RuntimeException('Required binding has no actual native case evidence.'); }
    return $identities;
}

/** Same callable is used by the engine controller and explicit Composer verify:server entry.
 * @param list<string> $arguments */
function server_verify_run(array $arguments): array
{
    $directory = null;
    try {
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__)); chdir($root);
    $publicSources = server_verify_source_admission($root); // FIRST: no native subprocess or product autoload before source refusal.
    foreach (['JsonRecord', 'SqlColumn', 'DatabaseInteger', 'DatabaseFailure'] as $utility) { require_once $root . '/app/' . $utility . '.php'; }
    require_once __DIR__ . '/ServerTestEvidence.php';
    $options = server_verify_options($arguments);
    if (getenv('TABLO_TEST_DB_DRIVER') !== $options['engine']) { throw new RuntimeException('Explicit selected fixture engine absent.'); }
    $map = server_verify_manifest($root); server_verify_families($root, $map);
    server_verify_declared_lanes($root, $map, $options['lanes']);
    $main = server_verify_runtime($root);
    require_once $root . '/vendor/autoload.php';
    server_verify_discovered_lanes($root, $map, $options['lanes']);
    $directory = new TemporaryDirectory('tablo-server-verify-');
    $clean = server_verify_environment();
    $serverEnvironment = $clean + ['TABLO_TEST_DB_DRIVER' => $options['engine']];
    require_once __DIR__ . '/ServerFixturePlan.php'; require_once __DIR__ . '/ServerFixtureCatalog.php'; require_once __DIR__ . '/server-cell.php';
    server_cell_observer_remaining();
    $context = server_cell_context($root, $serverEnvironment); $catalogs = server_cell_catalogs($context);
    $availability = server_cell_availability($context);
    $baseline = server_verify_baseline($root, $map, $context['plan']);
    $results = server_verify_lanes($root, $directory, $map, ['context' => $context, 'catalogs' => $catalogs, 'publicSources' => $publicSources], $options);
    $output = $main . '/server-' . $options['engine'] . '-' . bin2hex(random_bytes(8)) . '.json';
    $record = ['schema' => 'tablo/server-native-evidence/v1', 'status' => 'passed', 'engine' => $options['engine'], 'requiredLanes' => $options['lanes'],
        'availability' => $availability, 'baseline' => $baseline, 'actual' => $results,
        'sourceManifest' => \Tablo\RequiredFile::digest($root . '/contracts/reviewed-dependencies.json'),
        'completeIssueAcceptance' => false, 'remainingAggregateSublanes' => $map['aggregate'] ?? null];
    server_verify_retain($output, $record);
    return ['status' => 'passed', 'engine' => $options['engine'], 'lanes' => $options['lanes'], 'evidence' => $output, 'issueAccepted' => false];
    } catch (Throwable $error) { $directory?->retainFailure($error); throw $error; }
}

/** Captured before children under the frozen, quiescent source lifecycle; not a defense against concurrent catalog mutation.
 * @return list<string> */
function server_verify_source_admission(string $root): array
{
    require_once __DIR__ . '/reviewed-dependencies.php';
    verify_worker_dependencies($root);
    return array_keys(worker_dependencies());
}

/** @param list<string> $lanes */
function server_verify_declared_lanes(string $root, array $map, array $lanes): void
{
    foreach ($lanes as $lane) { server_verify_bindings($root, $map, \Tablo\Tools\server_native_cases($map, $lane), $lane); }
}

/** @param list<string> $lanes */
function server_verify_discovered_lanes(string $root, array $map, array $lanes): void
{
    foreach ($lanes as $lane) { server_verify_discovery($root, \Tablo\Tools\server_native_cases($map, $lane), $lane); }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    /** @var list<string> $argv */
    try { fwrite(STDOUT, \Tablo\JsonEncoding::encode(server_verify_run(array_slice($argv, offset: 1))) . "\n"); }
    catch (Throwable) { fwrite(STDERR, data: "Server verification failed.\n"); exit(1); }
}
