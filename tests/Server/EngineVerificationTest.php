<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

/** Wire-evidence and process-environment checks; these do not substitute for selected-engine cases. */
final class EngineVerificationTest
{
    #[Test]
    public function realHttpClosureRetainsActualSignalWithoutInventingNumericSuccess(): void
    { engine_verification_http_signal(); }

    #[Test]
    public function realDirectSignalClosureRemainsDistinctFromEofExitAndUnknown(): void
    { engine_verification_direct_signals(); }

    #[Test]
    public function availabilityClassificationRetainsStageAndCodesWithoutPrivateErrorText(): void
    { engine_verification_availability_failure(); engine_verification_declaration_failure(); engine_verification_prepare_failure(); }

    #[Test]
    public function initializeDiagnosticsRemainPrivateAndGuardFailureStaysNonzeroBeforeAutoload(): void
    { engine_verification_initialize_failure(); engine_verification_initialize_bootstrap(); }

    #[Test]
    public function nativeClientUsesPrivateConfigAndExactSocketWithoutSecretArgv(): void
    { engine_fixture_private_native_client(); }

    #[Test]
    public function optInNativeExportRejectsForeignPathsAndChangedChildEvidence(): void
    { engine_verification_native_export(); }

    #[Test]
    public function nonPassedZeroMissingExtraAndDuplicateJunitCannotBecomeNativeSuccess(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerTestEvidence.php';
        $directory = new TemporaryDirectory('tablo-server-junit-');
        try {
            $root = dirname(__DIR__, levels: 2); $required = engine_verification_required();
            $valid = engine_verification_testcase('passed', assertions: 1);
            $report = $directory->path . '/report.xml'; engine_verification_report($report, $valid);
            $accepted = \Tablo\Tools\server_native_junit($root, $report, $required);
            Assert::same($accepted['assertions'], 1); Assert::same(count($accepted['cases']), 1);
            foreach (['failed', 'error', 'skipped', 'todo', 'risky', 'flaky', 'aborted', 'unknown'] as $status) {
                engine_verification_report($report, engine_verification_testcase($status, assertions: 1));
                Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required)));
            }
            foreach ([engine_verification_testcase('passed', assertions: 0), '', $valid . $valid,
                str_replace('name="case"', replace: 'name="unreviewed"', subject: $valid),
                str_replace('time="0.01"', replace: 'time="8.01"', subject: $valid)] as $invalid) {
                engine_verification_report($report, $invalid);
                Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required)));
            }
            Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_summary(['status' => 'passed', 'totals' => ['total' => 1, 'passed' => 0, 'assertions' => 1]], required: 1, assertions: 1)));
            Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_summary(['status' => 'passed', 'totals' => ['total' => 1, 'passed' => 1, 'assertions' => 0]], required: 1, assertions: 1)));
        } finally { $directory->close(); }
    }

    #[Test]
    public function explicitReplacementEnvironmentDoesNotInheritParentCanary(): void
    {
        $directory = new TemporaryDirectory('tablo-server-environment-');
        $before = getenv('TABLO_TEST_PARENT_CANARY'); putenv('TABLO_TEST_PARENT_CANARY=synthetic-only');
        try {
            $environment = [];
            $systemRoot = getenv('SystemRoot');
            if (is_string($systemRoot)) { $environment['SystemRoot'] = $systemRoot; }
            $result = Subprocess::run([PHP_BINARY, '-n', '-r', 'echo json_encode(["isolated" => getenv("TABLO_TEST_PARENT_CANARY") === false], JSON_THROW_ON_ERROR);'],
                $directory, options: new CommandOptions(replacementEnvironment: $environment), environment: []);
            Assert::same($result['exit_code'], 0); Assert::same($result['stderr'], '');
            Assert::same(json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR), ['isolated' => true]);
            Assert::same(getenv('TABLO_TEST_PARENT_CANARY'), 'synthetic-only');
        } finally {
            putenv(is_string($before) ? 'TABLO_TEST_PARENT_CANARY=' . $before : 'TABLO_TEST_PARENT_CANARY');
            $directory->close();
        }
    }

    #[Test]
    public function baselineMethodElapsedIsDiagnosticAndNewServerWholeCaseBudgetStaysStrict(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerTestEvidence.php';
        $record = ['identity' => 'Tablo\\Tests\\Unit\\ReadApiRepositoryTest::actualPlannerUsesPrimaryKeyRangeAndReadOnlyReadsLeaveAllRowsUnchanged',
            'path' => 'tests/Unit/ReadApiRepositoryTest.php', 'minimumAssertions' => 1, 'deadlineSeconds' => null, 'dataRows' => []];
        $baseline = \Tablo\Tools\server_native_case_declaration($record, lane: 'baseline');
        Assert::null($baseline['deadlineSeconds']);
        Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_case_declaration($record, lane: 'main')));
        $document = new \DOMDocument(); $case = $document->createElement('testcase');
        if (!$case instanceof \DOMElement) { throw new \RuntimeException('Cannot create native evidence element.'); }
        $case->setAttribute('time', '64.195');
        Assert::same(\Tablo\Tools\server_native_elapsed($case, maximum: null), 64.195);
        Assert::true(engine_verification_refuses(static fn (): float => \Tablo\Tools\server_native_elapsed($case, maximum: 8)));
        $case->setAttribute('time', 'INF');
        Assert::true(engine_verification_refuses(static fn (): float => \Tablo\Tools\server_native_elapsed($case, maximum: null)));
    }

    #[Test]
    public function onlyTheOriginalWindowsHostCaseCanBeAQualifiedLinuxPlatformSkip(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerTestEvidence.php';
        $policy = \Tablo\Tools\server_native_baseline_platform_policy();
        $root = dirname(__DIR__, levels: 2); $directory = new TemporaryDirectory('tablo-platform-evidence-');
        try {
            $parts = explode('::', $policy['identity']);
            $file = htmlspecialchars($root . '/' . $policy['path'], ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
            $wire = '<testcase classname="' . $parts[0] . '" name="' . ($parts[1] ?? throw new \RuntimeException('Original method identity absent.')) . '" file="' . $file
                . '" assertions="0" time="0.01" testo:status="skipped"><skipped message="' . $policy['reason'] . '"/></testcase>';
            $spec = ['identity' => $policy['identity'], 'path' => $policy['path'], 'minimumAssertions' => 1, 'deadlineSeconds' => null];
            $required = [$policy['identity'] => $spec]; $report = $directory->path . '/report.xml';
            engine_verification_report($report, $wire);
            if (PHP_OS_FAMILY === 'Linux') {
                $actual = \Tablo\Tools\server_native_junit($root, $report, $required);
                Assert::same([$actual['platform'], $actual['cases'][$policy['identity']]['status'] ?? null, $actual['assertions']], ['Linux', 'skipped_platform', 0]);
            }
            if (PHP_OS_FAMILY !== 'Linux') { Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required))); }
            foreach ([str_replace($policy['reason'], replace: 'Different reason', subject: $wire),
                str_replace('assertions="0"', replace: 'assertions="1"', subject: $wire),
                str_replace('testo:status="skipped"', replace: 'testo:status="cancelled"', subject: $wire),
                str_replace('</testcase>', replace: '<error/></testcase>', subject: $wire),
                str_replace('</testcase>', replace: '<skipped message="' . $policy['reason'] . '"/></testcase>', subject: $wire)] as $invalid) {
                engine_verification_report($report, $invalid);
                Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required)));
            }
            engine_verification_report($report, engine_verification_testcase('skipped', assertions: 0));
            Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, engine_verification_required())));
            engine_verification_baseline_summary($policy['identity']);
        } finally { $directory->close(); }
    }


}

/** The aggregate fixture checks summary agreement without replacing real native platform metadata. */
function engine_verification_baseline_summary(string $identity): void
{
    $cases = [];
    for ($index = 0; $index < 440; $index++) { $cases['Synthetic\\Baseline::case' . $index] = ['status' => 'passed', 'assertions' => 1, 'seconds' => 0.01]; }
    $linux = PHP_OS_FAMILY === 'Linux'; $passed = $linux ? 440 : 441;
    $cases[$identity] = ['status' => $linux ? 'skipped_platform' : 'passed', 'assertions' => $linux ? 0 : 1, 'seconds' => 0.01];
    $native = ['cases' => $cases, 'assertions' => $passed, 'platform' => PHP_OS_FAMILY];
    $summary = ['status' => 'passed', 'totals' => ['total' => 441, 'passed' => $passed, 'assertions' => $passed, 'skipped' => $linux ? 1 : 0], 'failures' => []];
    \Tablo\Tools\server_native_baseline_summary($summary, $native); Assert::same(count($native['cases']), 441);
    foreach (['error' => 1, 'skipped' => 2] as $name => $count) {
        $wrong = $summary; $wrong['totals'][$name] = $count; $refused = false;
        try { \Tablo\Tools\server_native_baseline_summary($wrong, $native); } catch (\RuntimeException) { $refused = true; }
        Assert::true($refused);
    }
    $native['platform'] = PHP_OS_FAMILY === 'Linux' ? 'Windows' : 'Linux'; $refused = false;
    try { \Tablo\Tools\server_native_baseline_summary($summary, $native); } catch (\RuntimeException) { $refused = true; }
    Assert::true($refused);
}

/** Synthetic wire rejection tests only; the real existing Testo child is required by the engine cell. */
function engine_verification_native_export(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerTestEvidence.php';
    $owner = new \Tablo\Tests\Support\TemporaryDirectory('tablo-server-verify-');
    try {
        $root = dirname(__DIR__, levels: 2); $output = str_replace('\\', replace: '/', subject: $owner->path) . '/composer-verify';
        $environment = ['TABLO_TEST_RUN_NONCE' => (string) getenv('TABLO_TEST_RUN_NONCE'), 'TABLO_TEST_SOURCE_ROOT' => $root];
        \Testo\Assert::same(\Tablo\Tools\server_verify_native_directory($root, $output, $environment), $output);
        foreach (['', $output . '/extra', dirname($output), $output . '/../elsewhere', dirname($root) . '/source/composer-verify'] as $invalid) {
            \Testo\Assert::true(engine_verification_native_refuses(static fn (): string => \Tablo\Tools\server_verify_native_directory($root, $invalid, $environment)));
        }
        \Testo\Assert::true(engine_verification_native_refuses(static fn (): string => \Tablo\Tools\server_verify_native_directory($root, $output, [])));
        if (!mkdir($output, permissions: 0o700)) { throw new \RuntimeException('Cannot reserve synthetic evidence directory.'); }
        \Testo\Assert::true(engine_verification_native_refuses(static fn (): string => \Tablo\Tools\server_verify_native_directory($root, $output, $environment)));
        engine_verification_native_wire($root, $output);
    } finally { $owner->close(); }
}

function engine_verification_native_wire(string $root, string $directory): void
{
    $base = [PHP_BINARY, 'vendor/bin/testo', 'run', '--path', 'tests/Unit', '--path', 'tests/Network', '--path', 'tests/Http'];
    \Testo\Assert::same(\Tablo\Tools\server_verify_native_command(), $base);
    $command = \Tablo\Tools\server_verify_native_command($directory . '/baseline.xml');
    \Testo\Assert::same(array_slice($command, offset: 0, length: count($base)), $base);
    \Testo\Assert::same(array_slice($command, offset: count($base)), ['--json', '--log-junit=' . $directory . '/baseline.xml', '--no-ansi']);
    $prefix = $directory . '/gate-' . bin2hex(random_bytes(8));
    file_put_contents($prefix . '.stdout', data: 'synthetic-json'); file_put_contents($prefix . '.stderr', data: 'synthetic-stderr');
    $started = ['pid' => 17, 'argv' => $command, 'cwd' => $root];
    $closed = $started + ['proc_close' => 0, 'exit' => 0, 'stdout_sha256' => hash('sha256', data: 'synthetic-json'), 'stderr_sha256' => hash('sha256', data: 'synthetic-stderr')];
    file_put_contents($prefix . '-started.json', \Tablo\JsonEncoding::encode($started));
    file_put_contents($prefix . '-closed.json', \Tablo\JsonEncoding::encode($closed));
    $gate = \Tablo\Tools\server_verify_native_gate($root, $directory, $prefix, $command);
    \Testo\Assert::same([$gate['pid'], $gate['stdout']], [17, 'synthetic-json']);
    foreach (['pid' => null, 'exit' => null, 'proc_close' => -1, 'argv' => [...$command, '--filter=omit'], 'cwd' => $directory] as $key => $value) {
        $invalid = $closed; $invalid[$key] = $value;
        file_put_contents($prefix . '-closed.json', \Tablo\JsonEncoding::encode($invalid));
        \Testo\Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_verify_native_gate($root, $directory, $prefix, $command)));
    }
    file_put_contents($prefix . '-closed.json', \Tablo\JsonEncoding::encode($closed));
    foreach (['stdout', 'stderr'] as $stream) {
        $path = $prefix . '.' . $stream; $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path));
        file_put_contents($path, data: 'changed');
        \Testo\Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_verify_native_gate($root, $directory, $prefix, $command)));
        file_put_contents($path, $bytes);
    }
    \Testo\Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_verify_native_gate($root, $directory, dirname($directory) . '/gate-0123456789abcdef', $command)));
    \Testo\Assert::same(\Tablo\Tools\server_verify_native_gate($root, $directory, $prefix, $command)['pid'], 17);
}

function engine_verification_native_refuses(\Closure $operation): bool
{
    try { $operation(); } catch (\RuntimeException) { return true; }
    return false;
}

/** Real argv/stdin transport and ownership refusal; this helper never claims to be an engine. */
function engine_fixture_private_native_client(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-native-client-argv-');
    try {
        $nonce = bin2hex(random_bytes(16)); $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'private-native-client');
        $context = ['plan' => ['nonce' => $nonce, 'services' => []], 'directory' => $directory, 'environment' => []];
        foreach (['mysql', 'mariadb'] as $engine) {
            $name = 'tablo-i39-' . substr($nonce, offset: 0, length: 12) . '-' . $engine;
            $context['plan']['services'][$engine] = ['container' => $name, 'containerId' => str_repeat($engine === 'mysql' ? 'a' : 'b', times: 64),
                'socket' => '/tmp/' . $name . '.sock', 'password' => $secret,
                'rootPasswordFile' => $directory->path . '/never-open-password', 'rootOptionsFile' => $directory->path . '/never-open-options'];
        }
        foreach (['mysql', 'mariadb'] as $engine) { engine_fixture_native_client_transport($context, $engine, $secret); engine_fixture_pinned_readiness_command($context, $engine); }
        engine_fixture_native_client_foreign($context);
        engine_fixture_final_daemon_readiness();
        engine_fixture_native_fact_line_endings();
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

/** @param array{plan: array, directory: \Tablo\Tests\Support\TemporaryDirectory, environment: array<string, string>} $context */
function engine_fixture_native_client_transport(array $context, string $engine, #[\SensitiveParameter] string $secret): void
{
    $paths = \server_cell_client_paths($context, $engine); $arguments = \server_cell_client_arguments($context, $engine);
    $sql = \server_cell_native_readiness_sql();
    Assert::same($sql, 'SELECT @@skip_networking, @@port, @@require_secure_transport;');
    $actor = 'fwrite(STDOUT,json_encode(["argv"=>array_slice($argv,1),"input"=>stream_get_contents(STDIN)],JSON_THROW_ON_ERROR));';
    $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, '-r', $actor, ...$arguments], $context['directory'], [], new \Tablo\Tests\Support\CommandOptions(input: $sql));
    Assert::same([$child['exit_code'], $child['stderr']], [0, '']);
    $wire = \Tablo\JsonRecord::decode($child['stdout']);
    $actual = \Tablo\JsonRecord::checked($wire['argv'] ?? null);
    Assert::true(in_array('--protocol=socket', $actual, strict: true));
    Assert::true(in_array('--socket=' . $paths['socket'], $actual, strict: true));
    Assert::true(in_array('--defaults-extra-file=' . $paths['options'], $actual, strict: true));
    Assert::same($wire['input'] ?? null, $sql); Assert::false(in_array($sql, $actual, strict: true));
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $secret));
    Assert::same($paths['directory'], '/run/tablo-i39-' . \Tablo\SqlColumn::string($context['plan'], 'nonce') . '-' . $engine . '-client');
    $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($child['files']['receipt']);
    Assert::same([$receipt['closed'], $receipt['root_exit_observed'], $receipt['exit_code']], [true, true, 0]);
    Assert::true(\Tablo\SqlColumn::integer($receipt, 'pid') > 0);
    Assert::false(file_exists($context['directory']->path . '/never-open-password'));
    Assert::false(file_exists($context['directory']->path . '/never-open-options'));
}

/** @param array{plan: array, directory: \Tablo\Tests\Support\TemporaryDirectory, environment: array<string, string>} $context */
function engine_fixture_native_client_foreign(array $context): void
{
    foreach ([[$context['plan'], 'foreign'],
        [array_replace_recursive($context['plan'], ['services' => ['mysql' => ['socket' => '/tmp/foreign.sock']]]), 'mysql'],
        [array_replace_recursive($context['plan'], ['services' => ['mysql' => ['containerId' => 'not-a-saved-id']]]), 'mysql'],
        [array_replace_recursive($context['plan'], ['services' => ['mysql' => ['container' => 'foreign']]]), 'mysql']] as [$invalidPlan, $engine]) {
        $invalid = ['plan' => $invalidPlan, 'directory' => $context['directory'], 'environment' => $context['environment']];
        $caught = null; try { \server_cell_client_arguments($invalid, $engine); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class);
    }
}


/** Native exception values are synthetic here; only a later real TLS PDO child can qualify availability. */
function engine_verification_availability_failure(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'availability-diagnostic');
    $error = new \PDOException($secret); $error->errorInfo = ['HY000', 2_002, $secret];
    $record = \server_cell_availability_failure($error, engine: 'mysql', stage: 'connection');
    Assert::same($record['schema'], 'tablo/server-availability-failure/v1');
    Assert::same([$record['engine'], $record['stage']], ['mysql', 'connection']);
    Assert::same([$record['type'], $record['sqlState'], $record['nativeCode'], $record['nativeReason']], ['pdo', 'HY000', 2_002, 'unknown']);
    Assert::null($record['category']);
    Assert::same($record['source'], 'tests/Server/EngineVerificationTest.php');
    Assert::true(is_int($record['line']) && $record['line'] > 0);
    $wire = \Tablo\JsonEncoding::encode($record);
    Assert::false(str_contains($wire, $secret)); Assert::false(str_contains($wire, dirname(__DIR__, levels: 2)));
    foreach (['message', 'trace', 'previous', 'errorInfo'] as $field) { Assert::false(array_key_exists($field, $record)); }
    $error->errorInfo = [$secret, '2002', $secret];
    $invalid = \server_cell_availability_failure($error, $secret, $secret);
    Assert::same([$invalid['engine'], $invalid['stage'], $invalid['sqlState'], $invalid['nativeCode']], [null, 'unknown', null, null]);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($invalid), $secret));
    $runtime = \server_cell_availability_failure(new \RuntimeException($secret), engine: 'mariadb', stage: 'fixture');
    Assert::same([$runtime['type'], $runtime['sqlState'], $runtime['nativeCode']], ['runtime', null, null]);
    $database = \server_cell_availability_failure(new \Tablo\DatabaseFailure('unavailable'), engine: 'mysql', stage: 'connection');
    Assert::same([$database['type'], $database['category']], ['database', 'unavailable']);
}

/** Synthetic exceptions exercise only the diagnostic boundary, never selected-engine behavior. */
function engine_verification_declaration_failure(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'declaration-canary');
    engine_verification_declaration_mode();
    $error = new \RuntimeException($secret, previous: new \RuntimeException($secret));
    $record = \server_cell_declaration_failure($error, checkpoint: 'PLAN_WRITE');
    Assert::same(array_keys($record), ['schema', 'status', 'stage', 'type', 'source', 'line', 'executed']);
    Assert::same([$record['status'], $record['stage'], $record['type'], $record['executed']], ['failed', 'PLAN_WRITE', 'runtime', false]);
    Assert::same($record['source'], 'tests/Server/EngineVerificationTest.php');
    $wire = json_encode($record, JSON_THROW_ON_ERROR);
    Assert::false(str_contains($wire, $secret)); Assert::false(str_contains($wire, dirname(__DIR__, levels: 2)));
    Assert::same(\server_cell_declaration_record('TABLO_DECLARATION ' . $wire . "\nNative server cell failed.\n"), $record);
    foreach (['server_fixture_plan_bootstrap_ini' => 'BOOTSTRAP_INI', 'server_fixture_plan_tool' => 'TOOL_INPUT',
        'server_fixture_plan_linux_inputs' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_file' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_provenance' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_receipt' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_qualification' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_runtimes' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_runtime_binding' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_references' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_reference' => 'LINUX_QUALIFICATION', 'server_fixture_plan_linux_path' => 'LINUX_QUALIFICATION',
        'server_fixture_plan_linux_shell' => 'LINUX_QUALIFICATION', 'server_fixture_plan_nonce' => 'OPTIONS',
        'server_fixture_plan_php_modules' => 'BOOTSTRAP_MODULES', 'server_fixture_plan_php_module_decode' => 'BOOTSTRAP_MODULES',
        'server_fixture_plan_php_module_record' => 'BOOTSTRAP_MODULES', 'server_fixture_plan_php_module_pins' => 'BOOTSTRAP_MODULES',
        'server_fixture_plan_source_manifest' => 'SOURCE_MANIFEST', 'server_fixture_plan_vendor' => 'VENDOR_REFERENCES',
        'server_fixture_plan_vendor_files' => 'VENDOR_FILES', 'server_fixture_plan_mago_files' => 'MAGO_FILES', 'verify_worker_dependencies' => 'DEPENDENCY_GUARD'] as $function => $stage) {
        Assert::same(\server_cell_declaration_stage([['function' => $function, 'args' => [$secret]]], checkpoint: 'PINS'), $stage);
    }
    Assert::same(\server_cell_declaration_stage([['function' => 'server_fixture_plan_tool', 'class' => $secret, 'args' => [$secret]]], $secret), 'UNKNOWN');
    foreach ([$secret, dirname(__DIR__, levels: 2) . '-foreign/tools/server-cell.php', dirname(__DIR__, levels: 2) . '/tools/../tools/server-cell.php'] as $origin) {
        Assert::same(\server_cell_declaration_origin($origin, line: 123), ['source' => null, 'line' => null]);
    }
    foreach (['', 'TABLO_DECLARATION {', 'TABLO_DECLARATION ' . $wire . "\nTABLO_DECLARATION " . $wire, str_repeat('x', times: 4097)] as $invalid) {
        Assert::same(\server_cell_declaration_record($invalid), null);
    }
    foreach ([['message' => $secret], ['stage' => $secret], ['type' => $secret], ['source' => $secret], ['line' => 0], ['executed' => true], ['status' => 'passed']] as $changes) {
        Assert::same(\server_cell_declaration_record('TABLO_DECLARATION ' . json_encode(array_replace($record, $changes), JSON_THROW_ON_ERROR)), null);
    }
    Assert::same(\server_cell_declaration_record('TABLO_DECLARATION ' . str_replace('"status":"failed"', replace: '"status":"failed","status":"failed"', subject: $wire)), null);
}

function engine_verification_prepare_failure(): void
{
    $root = str_replace('\\', replace: '/', subject: dirname(__DIR__, levels: 2));
    $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'prepare-diagnostic');
    $error = new \RuntimeException($secret);
    $record = \server_cell_prepare_failure($error, checkpoint: 'EXECUTE', roots: [$root]);
    Assert::same($record['schema'], 'tablo/server-prepare-failure/v5'); Assert::null($record['capability']);
    Assert::same([$record['phase'], $record['rootCause'], $record['issueAccepted'], $record['errorClass']], ['prepare', 'UNKNOWN', false, 'RuntimeException']);
    Assert::same($record['source'], 'tests/Server/EngineVerificationTest.php');
    Assert::true(is_int($record['line']) && $record['line'] > 0);
    $wire = \Tablo\JsonEncoding::encode($record);
    Assert::false(str_contains($wire, $secret)); Assert::false(str_contains($wire, $root));
    $caught = null; $retained = false;
    try { \server_cell_prepare_rethrow($error, failure: ['checkpoint' => 'EXECUTE', 'roots' => [$root]], ready: false, retain: static function () use (&$retained): void { $retained = true; throw new \RuntimeException('secondary'); }); }
    catch (\Throwable $original) { $caught = $original; }
    Assert::same($caught, $error); Assert::true($retained);
    $observation = \server_cell_capability_observation(['exit_code' => null, 'stdout' => '', 'stderr' => $secret]);
    Assert::same([$observation['reportedExit'], $observation['stdout'], $observation['stderr'], $observation['category']], [null, 'EMPTY', 'NONEMPTY', 'UNKNOWN']);
    Assert::same([$observation['schema'], $observation['stderrKind'], $observation['stderrReason']], ['tablo/php-capability-observation/v4', 'UNKNOWN', 'TERMINATOR_MISSING']);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($observation), $secret));
}

function engine_verification_declaration_mode(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
    $arguments = ['--plan=/synthetic/private-plan.json', '--engine=mysql', '--declaration-diagnostic'];
    $options = \server_cell_plan_options($arguments);
    Assert::same([$options['diagnostic'], $options['pull'], $options['cross']], [true, false, false]);
    foreach (['--pull', '--cross', '--declaration-diagnostic'] as $invalid) {
        $caught = null;
        try { \server_cell_plan_options([...$arguments, $invalid]); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class);
    }
    Assert::same(\Tablo\Tools\server_fixture_plan_linux_inputs('/synthetic/unopened-source', executionMode: 'diagnostic-only'), []);
    $failure = null;
    try { \Tablo\Tools\server_fixture_plan_linux_inputs('/synthetic/unopened-source', executionMode: 'unknown'); }
    catch (\RuntimeException $error) { $failure = $error; }
    Assert::same($failure?->getMessage(), 'Explicit execution mode required.');
    engine_verification_declaration_nonce();
    engine_verification_qualification_components();
    engine_verification_bootstrap_modules();
}

function engine_verification_declaration_nonce(): void
{
    $nonce = str_repeat('a', times: 32);
    Assert::same(\Tablo\Tools\server_fixture_plan_nonce(executionMode: 'qualified', supplied: $nonce), $nonce);
    foreach ([false, '', str_repeat('a', times: 31), str_repeat('a', times: 33), str_repeat('A', times: 32), $nonce . "\n"] as $invalid) {
        $caught = null;
        try { \Tablo\Tools\server_fixture_plan_nonce(executionMode: 'qualified', supplied: $invalid); }
        catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Explicit fresh qualified cell nonce required.');
    }
    foreach (['', $nonce] as $override) {
        $caught = null;
        try { \Tablo\Tools\server_fixture_plan_nonce(executionMode: 'diagnostic-only', supplied: $override); }
        catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Diagnostic-only nonce override refused.');
    }
    $first = \Tablo\Tools\server_fixture_plan_nonce(executionMode: 'diagnostic-only', supplied: false);
    $second = \Tablo\Tools\server_fixture_plan_nonce(executionMode: 'diagnostic-only', supplied: false);
    Assert::same(preg_match('/\A[0-9a-f]{32}\z/D', $first), 1);
    Assert::same(preg_match('/\A[0-9a-f]{32}\z/D', $second), 1); Assert::false($first === $second);
}

/** In-memory fragments test declaration components only; no qualified receipt is fabricated or written. */
function engine_verification_qualification_components(): void
{
    $pin = ['path' => '/synthetic/unopened/tool', 'sha256' => str_repeat('a', times: 64)];
    Assert::same(\Tablo\Tools\server_fixture_plan_linux_reference($pin), $pin);
    foreach ([[], ['sha256' => $pin['sha256'], 'path' => $pin['path']], $pin + ['extra' => 'refused'],
        array_replace($pin, ['sha256' => str_repeat('A', times: 64)])] as $invalid) {
        Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_linux_reference($invalid)));
    }
    foreach (['relative', '/synthetic/../foreign', '/synthetic/./tool', '/synthetic//tool', '/synthetic/tool/', "/synthetic/\0tool", '/synthetic/\\tool', str_repeat('/', times: 4097)] as $path) {
        Assert::true(engine_verification_native_refuses(static function () use ($path): void { \Tablo\Tools\server_fixture_plan_linux_path($path); }));
    }
    engine_verification_runtime_pin_components($pin);
    engine_verification_reference_components($pin);
    foreach ([null, false, '', 'diagnostic-only', 'QUALIFIED', 'unknown'] as $mode) {
        $caught = null;
        try { \server_cell_observer_require(['executionMode' => $mode]); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Qualified execution mode required.');
    }
    $missing = null;
    try { \server_cell_observer_require([]); } catch (\RuntimeException $error) { $missing = $error; }
    Assert::same($missing?->getMessage(), 'Qualified execution mode required.');
}

/** All paths remain unopened synthetic strings; this is not native runtime attestation. */
/** @param array{path: string, sha256: string} $pin */
function engine_verification_runtime_pin_components(array $pin): void
{
    $runtimes = [];
    foreach (['node', 'php', 'composer', 'shell', 'docker'] as $name) { $runtimes[$name] = ['path' => '/synthetic/unopened/' . $name, 'sha256' => $pin['sha256']]; }
    \Tablo\Tools\server_fixture_plan_linux_runtime_binding(['runtimes' => $runtimes], $runtimes);
    foreach (['node', 'php', 'composer', 'shell', 'docker'] as $name) {
        $changed = $runtimes; $changed[$name]['sha256'] = str_repeat('b', times: 64);
        Assert::true(engine_verification_native_refuses(static function () use ($runtimes, $changed): void {
            \Tablo\Tools\server_fixture_plan_linux_runtime_binding(['runtimes' => $changed], $runtimes);
        }));
    }
    foreach ([array_reverse($runtimes, preserve_keys: true), array_slice($runtimes, offset: 0, length: 4), $runtimes + ['extra' => $pin]] as $invalid) {
        Assert::true(engine_verification_native_refuses(static function () use ($invalid, $runtimes): void {
            \Tablo\Tools\server_fixture_plan_linux_runtime_binding(['runtimes' => $invalid], $runtimes);
        }));
    }
}

/** Partial reference fields have no status/schema/nonce claim and cannot be a qualification receipt. */
/** @param array{path: string, sha256: string} $pin */
function engine_verification_reference_components(array $pin): void
{
    $root = '/synthetic/unopened/tablo-linux-' . str_repeat('a', times: 32); $references = [];
    foreach (['runtimeControls' => 'runtime-controls.json', 'platformReceipt' => 'platform.json',
        'neutralControls' => 'neutral-controls.json', 'fallbackControls' => 'fallback-controls.json'] as $name => $file) {
        $references[$name] = array_replace($pin, ['path' => $root . '/' . $file]);
        if ($name !== 'neutralControls') { $references[$name . 'Sha256'] = $pin['sha256']; }
    }
    \Tablo\Tools\server_fixture_plan_linux_references($references, $root);
    foreach (['runtimeControls', 'platformReceipt', 'neutralControls', 'fallbackControls'] as $name) {
        $foreign = $references; $foreign[$name] = array_replace($pin, ['path' => $root . '-foreign/' . $name . '.json']);
        Assert::true(engine_verification_native_refuses(static function () use ($foreign, $root): void { \Tablo\Tools\server_fixture_plan_linux_references($foreign, $root); }));
    }
    foreach (['runtimeControls', 'platformReceipt', 'fallbackControls'] as $name) {
        $changed = $references; $changed[$name . 'Sha256'] = str_repeat('b', times: 64);
        Assert::true(engine_verification_native_refuses(static function () use ($changed, $root): void { \Tablo\Tools\server_fixture_plan_linux_references($changed, $root); }));
    }
}

/** In-memory prepared input only: no modules, INIs or capability probes are opened or executed. */
function engine_verification_bootstrap_modules(): void
{
    $ini = [['path' => '/synthetic/unopened/php.ini', 'sha256' => str_repeat('a', times: 64)],
        ['path' => '/synthetic/unopened/extensions.ini', 'sha256' => str_repeat('b', times: 64)]];
    $extensions = [['path' => '/synthetic/unopened/first.so', 'sha256' => str_repeat('c', times: 64)],
        ['path' => '/synthetic/unopened/second.so', 'sha256' => str_repeat('d', times: 64)]];
    $record = ['schema' => 'tablo/php-bootstrap-modules/v1', 'iniFiles' => $ini, 'extensionFiles' => $extensions];
    Assert::same(\Tablo\Tools\server_fixture_plan_php_module_record($record, $ini), $extensions);
    Assert::same(\Tablo\Tools\server_fixture_plan_php_module_decode(json_encode($record, JSON_THROW_ON_ERROR), $ini), $extensions);
    $empty = $record; $empty['extensionFiles'] = [];
    Assert::same(\Tablo\Tools\server_fixture_plan_php_module_decode(json_encode($empty, JSON_THROW_ON_ERROR), $ini), []);
    foreach ([$record + ['extra' => 'refused'], array_reverse($record, preserve_keys: true), array_replace($record, ['schema' => 'unknown']),
        array_replace($record, ['iniFiles' => array_reverse($ini)]), array_replace($record, ['iniFiles' => []]),
        array_replace($record, ['extensionFiles' => [$extensions[0], $extensions[0]]])] as $invalid) {
        Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_php_module_record($invalid, $ini)));
    }
    Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_php_module_record($record, [])));
    $changed = $ini; $changed[0]['sha256'] = str_repeat('e', times: 64);
    Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_php_module_record($record, $changed)));
    $wire = json_encode($empty, JSON_THROW_ON_ERROR);
    foreach (['[]', '{}', '{', str_repeat('x', times: 131_073), str_replace('"extensionFiles":[]', replace: '"extensionFiles":{}', subject: $wire)] as $invalid) {
        Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_php_module_decode($invalid, $ini)));
    }
    engine_verification_bootstrap_pin_envelope();
    Assert::same(\Tablo\Tools\server_fixture_plan_linux_inputs('/synthetic/unopened/source', executionMode: 'diagnostic-only', nonce: '', iniFiles: ['invalid-unopened-pins']), []);
}

/** Pin-list refusals do not stat even the mentioned nonexistent paths. */
function engine_verification_bootstrap_pin_envelope(): void
{
    $pin = ['path' => '/synthetic/unopened/module.so', 'sha256' => str_repeat('a', times: 64)];
    $pins = [];
    for ($index = 0; $index < 128; ++$index) { $pins[] = array_replace($pin, ['path' => '/synthetic/unopened/module-' . $index . '.so']); }
    Assert::same(count(\Tablo\Tools\server_fixture_plan_php_module_pins($pins)), 128);
    $pins[] = array_replace($pin, ['path' => '/synthetic/unopened/module-128.so']);
    foreach ([$pins, [$pin, $pin], ['named' => $pin], [$pin + ['extra' => 'refused']],
        [array_replace($pin, ['path' => "/synthetic/unopened/\"module.so"])], [array_replace($pin, ['path' => "/synthetic/\nmodule.so"])],
        [array_replace($pin, ['path' => '/synthetic/../foreign.so'])], [array_replace($pin, ['sha256' => str_repeat('A', times: 64)])]] as $invalid) {
        Assert::true(engine_verification_native_refuses(static fn (): array => \Tablo\Tools\server_fixture_plan_php_module_pins($invalid)));
    }
}

/** Synthetic exceptions exercise only the diagnostic boundary, never selected-engine behavior. */
function engine_verification_initialize_failure(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'initialize-diagnostic');
    $error = new \PDOException($secret, previous: new \RuntimeException($secret)); $error->errorInfo = ['42000', 1_064, $secret];
    $progress = ['engine' => 'mysql', 'stage' => 'migration', 'point' => 'before-ddl', 'step' => 0];
    $record = \server_cell_initialize_failure($error, $progress);
    Assert::same($record['schema'], 'tablo/server-initialize-failure/v1');
    Assert::same([$record['engine'], $record['stage'], $record['point'], $record['step']], ['mysql', 'migration', 'before-ddl', 0]);
    Assert::same([$record['type'], $record['sqlState'], $record['nativeCode'], $record['nativeReason']], ['pdo', '42000', 1_064, 'unknown']);
    Assert::null($record['category']); Assert::same($record['source'], 'tests/Server/EngineVerificationTest.php');
    Assert::true(is_int($record['line']) && $record['line'] > 0);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($record), $secret));
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($record), dirname(__DIR__, levels: 2)));
    foreach (['message', 'trace', 'previous', 'errorInfo', 'sql', 'values'] as $field) { Assert::false(array_key_exists($field, $record)); }
    $error->errorInfo = [$secret, '1064', $secret];
    $invalid = \server_cell_initialize_failure($error, ['engine' => $secret, 'stage' => $secret, 'point' => $secret, 'step' => 18]);
    Assert::same([$invalid['engine'], $invalid['stage'], $invalid['point'], $invalid['step'], $invalid['sqlState'], $invalid['nativeCode']], [null, 'unknown', null, null, null, null]);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($invalid), $secret));
    foreach ([-1 => null, 0 => 0, 17 => 17, 18 => null] as $step => $expected) {
        $boundedProgress = $progress; $boundedProgress['step'] = $step;
        $bounded = \server_cell_initialize_failure(new \RuntimeException($secret), $boundedProgress);
        Assert::same($bounded['step'], $expected);
    }
    $database = \server_cell_initialize_failure(new \Tablo\DatabaseFailure('schema'), $progress);
    Assert::same([$database['type'], $database['category']], ['database', 'schema']);
    $runtime = \server_cell_initialize_failure(new \RuntimeException($secret), $progress);
    Assert::same([$runtime['type'], $runtime['sqlState'], $runtime['nativeCode']], ['runtime', null, null]);
    $nativeError = \server_cell_initialize_failure(new \Error($secret), $progress);
    Assert::same($nativeError['type'], 'error');
}

/** A fresh file-only CLI child must fail before any PDO, key or application bootstrap action. */
function engine_verification_initialize_bootstrap(): void
{
    $root = dirname(__DIR__, levels: 2); $directory = new TemporaryDirectory('tablo-initialize-diagnostic-');
    try {
        $source = engine_verification_initialize_source($root, $directory);
        $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'initialize-input');
        $path = $directory->path . '/initialize.json';
        $bytes = \Tablo\JsonEncoding::encode(['schema' => 'tablo/server-fixture-initialize/v1', 'nonce' => $secret, 'fixtures' => [], 'engine' => 'mysql']);
        Assert::same(file_put_contents($path, $bytes), strlen($bytes));
        $environment = ['TABLO_TEST_RUN_NONCE' => bin2hex(random_bytes(16)), 'TABLO_DB_DRIVER' => 'sqlite', 'TABLO_DB' => ':memory:',
            'TABLO_TOKEN_KEY_FILE' => '', 'TEMP' => $directory->path, 'TMP' => $directory->path];
        $systemRoot = getenv('SystemRoot'); if (is_string($systemRoot)) { $environment['SystemRoot'] = $systemRoot; }
        $result = Subprocess::run([PHP_BINARY, '-n', $source . '/tools/server-cell.php', '--initialize=' . $path], $directory, [],
            new CommandOptions(cwd: $directory->path, replacementEnvironment: $environment));
        Assert::same($result['exit_code'], 1); Assert::same($result['stdout'], '');
        $lines = explode("\n", rtrim($result['stderr'], characters: "\n"));
        Assert::same(count($lines), 2); Assert::same($lines[1] ?? null, 'Native server cell failed.');
        engine_verification_initialize_record(\Tablo\JsonRecord::decode($lines[0] ?? ''));
        $observation = \server_cell_initialize_observation($result) ?? throw new \RuntimeException('Expected initialization failure observation.');
        Assert::same($observation['diagnostic'], \Tablo\JsonRecord::decode($lines[0] ?? ''));
        Assert::same([$observation['schema'], $observation['reportedExit'], $observation['stdout'], $observation['stderr']],
            ['tablo/server-initialize-observation/v2', 1, 'EMPTY', 'NONEMPTY']);
        Assert::same($observation['rejection'], null);
        Assert::false(str_contains(\Tablo\JsonEncoding::encode($observation), $secret));
        Assert::false(str_contains($result['stderr'], $secret)); Assert::false(str_contains($result['stderr'], $directory->path));
        Assert::false(str_contains($result['stderr'], $root));
        $native = \Tablo\Tests\Support\SubprocessReceipt::read($result['files']['receipt']);
        Assert::true(is_int($native['pid']) && $native['pid'] > 0);
        Assert::same([$native['exit_code'], $native['proc_close'], $native['root_exit_observed'], $native['closed']], [1, 1, true, true]);
        Assert::same([$native['signaled'], $native['term_signal']], [false, null]);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

function engine_verification_initialize_source(string $root, TemporaryDirectory $directory): string
{
    $source = $directory->path . '/source';
    foreach (['tools/server-cell.php', 'tools/reviewed-dependencies.php', 'app/RequiredFile.php',
        'tools/ReviewedDependencyManifest.php', 'tools/ReviewedDependencyCatalog.php', 'tools/ReviewedDependencyEntry.php',
        'tools/ReviewedDependencyCoverage.php', 'tools/MagoSource.php', 'tools/reviewed-dependency-map.json'] as $relative) {
        $parent = dirname($source . '/' . $relative);
        if (!is_dir($parent)) { Assert::true(mkdir($parent, permissions: 0o700, recursive: true)); }
        Assert::true(copy($root . '/' . $relative, $source . '/' . $relative));
    }
    Assert::false(file_exists($source . '/vendor/autoload.php'));
    Assert::false(file_exists($source . '/contracts/reviewed-dependencies.json'));
    return $source;
}

function engine_verification_initialize_record(array $record): void
{
    Assert::same($record['schema'] ?? null, 'tablo/server-initialize-failure/v1');
    Assert::same([$record['stage'] ?? null, $record['type'] ?? null], ['bootstrap', 'runtime']);
    Assert::same($record['source'] ?? null, 'tools/ReviewedDependencyManifest.php');
    Assert::same([$record['engine'] ?? null, $record['point'] ?? null, $record['step'] ?? null, $record['category'] ?? null,
        $record['sqlState'] ?? null, $record['nativeCode'] ?? null], [null, null, null, null, null, null]);
    Assert::true(is_int($record['line'] ?? null) && $record['line'] > 0);
}

/** @return array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: int}> */
function engine_verification_required(): array
{
    return ['Synthetic\\Evidence::case' => ['identity' => 'Synthetic\\Evidence::case', 'path' => 'tests/Server/EngineVerificationTest.php', 'minimumAssertions' => 1, 'deadlineSeconds' => 8]];
}


function engine_verification_testcase(string $status, int $assertions): string
{
    $file = htmlspecialchars(__FILE__, ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
    return '<testcase classname="Synthetic\\Evidence" name="case" file="' . $file . '" assertions="' . $assertions . '" time="0.01" testo:status="' . $status . '"/>';
}

function engine_verification_report(string $path, string $cases): void
{
    file_put_contents($path, '<?xml version="1.0"?><testsuites xmlns:testo="https://php-testo.github.io/schema/junit/1"><testsuite name="Synthetic">' . $cases . '</testsuite></testsuites>');
}

function engine_verification_refuses(\Closure $operation): bool
{
    try { $operation(); } catch (\RuntimeException) { return true; }
    return false;
}


/** A generated router has no product autoload, database, key, session or application side effect. */
function engine_verification_http_signal(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixtureCatalog.php';
    $directory = new TemporaryDirectory('tablo-http-signal-'); $server = null;
    try {
        $sink = \Tablo\Tests\Support\SessionEvidence::directory() ?? throw new \RuntimeException('Owned native receipt sink required.');
        $router = $directory->path . '/router.php'; file_put_contents($router, data: '<?php echo "synthetic";');
        $server = new \Tablo\Tests\Support\TestServer($directory, $router); $server->close();
        $records = engine_verification_http_records($sink, $directory->path); $record = $records['close'];
        Assert::same($record['pid'] ?? null, $records['start']['pid'] ?? null);
        Assert::same($record['closed'] ?? null, true); Assert::same($record['rootExitObserved'] ?? null, true);
        Assert::same($record['platform'] ?? null, PHP_OS_FAMILY);
        Assert::true(\Tablo\Tools\server_fixture_catalog_http_stopped($record, \Tablo\SqlColumn::integer($records['start'], 'pid')));
        $requests = \Tablo\JsonRecord::checked($record['terminationRequests'] ?? null); $request = \Tablo\JsonRecord::checked($requests[0] ?? null);
        Assert::true(count($requests) > 0); Assert::same($request['signal'] ?? null, 15); Assert::same($request['accepted'] ?? null, true);
        engine_verification_http_platform($record);
        $unknown = array_replace($record, ['observedExit' => null, 'procClose' => -1, 'signaled' => false, 'termSignal' => null]);
        Assert::false(\Tablo\Tools\server_fixture_catalog_http_stopped($unknown, \Tablo\SqlColumn::integer($records['start'], 'pid')));
        $wrongPid = array_replace($record, ['observedExit' => null, 'procClose' => -1, 'observedPid' => -1]);
        Assert::false(\Tablo\Tools\server_fixture_catalog_http_stopped($wrongPid, \Tablo\SqlColumn::integer($records['start'], 'pid')));
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $server?->close(); $directory->close(); }
}

/** @return array{start: array, close: array} */
function engine_verification_http_records(string $sink, string $fixture): array
{
    $starts = []; $closes = [];
    foreach (new \DirectoryIterator($sink) as $entry) {
        if ($entry->isLink()) { throw new \RuntimeException('Linked native signal evidence refused.'); }
        if (!$entry->isFile() || !str_ends_with($entry->getFilename(), '.json')) { continue; }
        $record = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($entry->getPathname())));
        if (str_starts_with($entry->getFilename(), 'server-start-') && ($record['fixture'] ?? null) === $fixture) { $starts[] = $record; }
        if (str_starts_with($entry->getFilename(), 'server-close-')) { $closes[] = $record; }
    }
    Assert::same(count($starts), 1); $start = $starts[0] ?? throw new \RuntimeException('Actual HTTP start missing.');
    $matching = array_values(array_filter($closes, static fn (array $record): bool => [$record['pid'] ?? null, $record['argv'] ?? null] === [$start['pid'] ?? null, $start['argv'] ?? null]));
    Assert::same(count($matching), 1);
    return ['start' => $start, 'close' => $matching[0] ?? throw new \RuntimeException('Actual HTTP close missing.')];
}

function engine_verification_http_platform(array $record): void
{
    if (PHP_OS_FAMILY === 'Linux') {
        Assert::same([$record['signaled'] ?? null, $record['termSignal'] ?? null], [true, 15]);
        Assert::null($record['observedExit'] ?? null); Assert::true(is_int($record['procClose'] ?? null));
        return;
    }
    Assert::same([$record['signaled'] ?? null, $record['termSignal'] ?? null], [false, null]);
    Assert::true(is_int($record['observedExit'] ?? null) || (is_int($record['procClose'] ?? null) && $record['procClose'] !== -1));
}

function engine_verification_direct_signals(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixtureCatalog.php';
    $directory = new TemporaryDirectory('tablo-direct-signals-');
    try {
        $control = Subprocess::run([PHP_BINARY, '-r', 'stream_get_contents(STDIN);exit(23);'], $directory, []);
        $eof = \Tablo\Tests\Support\SubprocessReceipt::read($control['files']['receipt']);
        Assert::same($control['exit_code'], 23);
        Assert::same([$eof['exit_code'], $eof['root_exit_observed'], $eof['closed']], [23, true, true]);
        Assert::same($eof['platform'] ?? null, PHP_OS_FAMILY); Assert::same($eof['signaled'] ?? null, false);
        Assert::null($eof['term_signal'] ?? null); Assert::true(is_int($eof['proc_close'] ?? null));
        Assert::true(\Tablo\Tools\server_fixture_catalog_direct_stopped($eof));
        foreach ([15, 9] as $signal) { engine_verification_direct_signal($directory, $signal); }
        engine_verification_unknown_signal($eof);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

function engine_verification_direct_signal(TemporaryDirectory $directory, int $signal): void
{
    $state = new \Tablo\Tests\Support\SubprocessState($directory, \Tablo\Tests\Support\StderrCapture::File); $primary = null;
    try {
        \Tablo\Tests\Support\SubprocessLaunch::launch($state, [PHP_BINARY, '-r', 'fwrite(STDOUT,"SIGNAL_READY\n");while(true){usleep(20000);}'], [], cwd: null);
        \Tablo\Tests\Support\SubprocessInput::input($state, input: '');
        \Tablo\Tests\Support\SubprocessSelection::waitForOutput($state, marker: 'SIGNAL_READY', timeout: 2);
        $state->event('terminate-direct-child', ['signal' => $signal, 'qualification' => 'owned regression request; actual observer remains authoritative']);
        $accepted = proc_terminate($state->ownedProcess(), $signal);
        $state->event('termination-request-result', ['signal' => $signal, 'accepted' => $accepted]); Assert::same($accepted, true);
        $until = hrtime(true) / 1_000_000_000 + 2;
        while (\Tablo\Tests\Support\SubprocessObservation::running($state) && hrtime(true) / 1_000_000_000 < $until) { usleep(20_000); }
    } catch (\Throwable $error) { $primary = $error; throw $error; }
    finally { \Tablo\Tests\Support\SubprocessClosure::close($state, $primary); }
    $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($state->files['receipt']);
    Assert::true(\Tablo\SqlColumn::integer($receipt, 'pid') > 0);
    Assert::same([$receipt['root_exit_observed'], $receipt['closed']], [true, true]);
    Assert::true(\Tablo\Tools\server_fixture_catalog_direct_stopped($receipt));
    Assert::true(is_int($receipt['proc_close'] ?? null));
    if (PHP_OS_FAMILY === 'Linux') {
        Assert::same([$receipt['exit_code'], $receipt['signaled'] ?? null, $receipt['term_signal'] ?? null], [null, true, $signal]);
        return;
    }
    Assert::same([$receipt['signaled'] ?? null, $receipt['term_signal'] ?? null], [false, null]); Assert::true(is_int($receipt['exit_code']));
}

function engine_verification_unknown_signal(array $receipt): void
{
    $unknown = array_replace($receipt, ['exit_code' => null, 'proc_close' => -1, 'signaled' => false, 'term_signal' => null]);
    Assert::false(\Tablo\Tools\server_fixture_catalog_direct_stopped($unknown));
    $signal = array_replace($unknown, ['platform' => 'Linux', 'signaled' => true, 'term_signal' => 15]);
    foreach ([['pid' => null], ['root_exit_observed' => false], ['closed' => false], ['platform' => 'Windows'], ['term_signal' => 0]] as $bad) {
        Assert::false(\Tablo\Tools\server_fixture_catalog_direct_stopped(array_replace($signal, $bad)));
    }
    $caught = null;
    try { \Tablo\Tests\Support\SubprocessReceiptData::checked(array_replace($signal, ['term_signal' => '15'])); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::instanceOf($caught, \RuntimeException::class);
}

/** Authored readiness records are negative controls only; actual engine/TLS availability remains a separate gate. */
function engine_fixture_final_daemon_readiness(): void
{
    $result = ['exit_code' => 0, 'stdout' => "0\t3306\t1\n", 'stderr' => ''];
    $receipt = ['pid' => 17, 'exit_code' => 0, 'closed' => true, 'root_exit_observed' => true, 'signaled' => false, 'term_signal' => null];
    Assert::true(\server_cell_native_ready_outcome($result, $receipt));
    foreach (["1\n", "1\t3306\t1\n", "0\t3307\t1\n", "0\t3306\t0\n", "0\t3306\n",
        "0\t3306\t1\n0\t3306\t1\n", " 0\t3306\t1\n", "0\t3306\t1\textra\n", ''] as $output) {
        Assert::false(\server_cell_native_ready_outcome(array_replace($result, ['stdout' => $output]), $receipt));
    }
    foreach ([['closed' => false], ['root_exit_observed' => false], ['pid' => 0], ['pid' => null], ['exit_code' => null],
        ['exit_code' => '0'], ['exit_code' => 1], ['signaled' => true, 'term_signal' => 15], ['term_signal' => 15]] as $invalid) {
        $observed = array_replace($receipt, $invalid);
        Assert::true(engine_verification_native_refuses(static fn (): bool => \server_cell_native_ready_outcome($result, $observed)));
    }
    foreach ([null, '0'] as $unknown) {
        $invalid = array_replace($result, ['exit_code' => $unknown]);
        Assert::true(engine_verification_native_refuses(static fn (): bool => \server_cell_native_ready_outcome($invalid, $receipt)));
    }
    Assert::false(\server_cell_native_ready_outcome(array_replace($result, ['exit_code' => 1]), array_replace($receipt, ['exit_code' => 1])));
    Assert::false(\server_cell_native_ready_outcome(array_replace($result, ['stderr' => 'warning']), $receipt));
}

/** Conditional CRLF source regression only; no actual native line ending or TLS result is inferred. */
function engine_fixture_native_fact_line_endings(): void
{
    $expected = ['8.4.12', 'localhost', "0\t3306\t1"];
    Assert::same(\server_cell_native_fact_lines("8.4.12\nlocalhost\n0\t3306\t1\n"), $expected);
    Assert::same(\server_cell_native_fact_lines("8.4.12\r\nlocalhost\r\n0\t3306\t1\r\n"), $expected);
    foreach (["8.4.12\rlocalhost\n0\t3306\t1\n", "8.4.12\n localhost\n0\t3306\t1\n", "8.4.12\nlocalhost\nforeign\n0\t3306\t1\n"] as $invalid) {
        Assert::false(\server_cell_native_fact_lines($invalid) === $expected);
    }
}

/** The same builder used by actual readiness must preserve the admitted private-config/host prefix.
 * @param array{plan: array, directory: \Tablo\Tests\Support\TemporaryDirectory, environment: array<string, string>} $context */
function engine_fixture_pinned_readiness_command(array $context, string $engine): void
{
    $selection = match (PHP_OS_FAMILY) {
        'Windows' => ['desktop-linux', 'npipe:////./pipe/dockerDesktopLinuxEngine'],
        'Linux' => ['default', 'unix:///var/run/docker.sock'],
        default => throw new \RuntimeException('Supported authored readiness platform absent.'),
    };
    $runtime = $context['directory']->path . '/tablo-server-' . \Tablo\SqlColumn::string($context['plan'], 'nonce');
    $plan = $context['plan'] + ['runtime' => $runtime, 'dockerPlatform' => PHP_OS_FAMILY, 'dockerContext' => $selection[0], 'dockerEndpoint' => $selection[1],
        'dockerContextConfig' => $context['directory']->path . '/synthetic-source/.docker', 'dockerConfig' => $runtime . '/private/docker-config', 'docker' => ['path' => 'never-executed-docker']];
    $plan['dockerAdmission'] = \server_cell_context_declaration($plan, PHP_OS_FAMILY)
        + ['metadataSha256' => str_repeat('a', times: 64), 'closureSha256' => str_repeat('b', times: 64)];
    $candidate = $context; $candidate['plan'] = $plan; $command = \server_cell_native_ready_command($candidate, $engine, PHP_OS_FAMILY);
    Assert::same($command, ['never-executed-docker', '--config', $runtime . '/private/docker-config', '--host', $selection[1], ...\server_cell_client_arguments($context, $engine)]);
    Assert::false(in_array('--context', $command, strict: true));
    $plan['dockerAdmission']['endpoint'] = 'tcp://foreign:2375'; $caught = null;
    $candidate['plan'] = $plan;
    try { \server_cell_native_ready_command($candidate, $engine, PHP_OS_FAMILY); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::instanceOf($caught, \RuntimeException::class);
}
