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
        engine_verification_failed_report();
        engine_verification_failed_hook();
        engine_verification_r74();
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
        $policies = engine_verification_platform_policies();
        $expected = [];
        foreach ($policies as $policy) { $expected[$policy['identity']] = ['path' => $policy['path'], 'reason' => $policy['reason']]; }
        Assert::same(\Tablo\Tools\server_native_baseline_platform_skips(), $expected);
        engine_verification_integrated_baseline($policies);
        $root = dirname(__DIR__, levels: 2); $directory = new TemporaryDirectory('tablo-platform-evidence-');
        try {
            foreach ($policies as $policy) { engine_verification_platform_case($root, $directory, $policy); }
            $report = $directory->path . '/report.xml';
            engine_verification_report($report, engine_verification_testcase('skipped', assertions: 0));
            Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, engine_verification_required())));
            engine_verification_baseline_summary($policies);
        } finally { $directory->close(); }
    }


}

function engine_verification_failed_report(): void
{
    $root = dirname(__DIR__, levels: 2); $identity = __FUNCTION__; $private = 'PRIVATE_MESSAGE_SQL_SECRET_HISTORY';
    $failure = ['test' => $identity, 'status' => 'failed', 'exception' => 'Testo\\Assert\\State\\Assertion\\ComparisonFailure',
        'message' => $private, 'file' => $root . '/vendor/testo/assert/Assert.php', 'line' => 57,
        'trace' => ['#0 ' . $root . '/tests/Server/EngineConstraintsTest.php:549: Testo\\Assert::same()'], 'output' => [['content' => $private]]];
    $report = ['status' => 'failed', 'failures' => [$failure]];
    $project = static fn (array $value): array => \Tablo\Tools\server_native_failure($root, \Tablo\JsonEncoding::encode($value), $identity, engine_verification_public_sources());
    $actual = $project($report);
    Assert::same($actual, ['schema' => 'tablo/server-native-failure/v2', 'rootCause' => 'UNKNOWN', 'reportStatus' => 'failed',
        'type' => 'assertion', 'origin' => 'trace', 'source' => 'tests/Server/EngineConstraintsTest.php', 'line' => 549,
        'category' => 'UNKNOWN', 'callerSource' => 'tests/Server/EngineConstraintsTest.php', 'callerLine' => 549]);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $private));
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $root));
    $direct = $failure; $direct['file'] = str_replace('/', replace: '\\', subject: $root . '/app/DatabaseTransferTarget.php');
    $direct['line'] = 99; $direct['exception'] = 'Tablo\\DatabaseFailure'; $direct['status'] = 'error';
    $actual = $project(['status' => 'failed', 'failures' => [$direct]]);
    Assert::same([$actual['type'], $actual['origin'], $actual['source'], $actual['line']], ['database', 'exception', 'app/DatabaseTransferTarget.php', 99]);
    engine_verification_failed_category($failure, $private, $project);
    engine_verification_failed_caller($root, $failure, $private, $project);
    engine_verification_failed_report_invalid($root, $identity, $failure, $private);
    engine_verification_failed_memory();
}

function engine_verification_failed_memory(): void
{
    $root = dirname(__DIR__, levels: 2);
    $identity = 'Tablo\\Tests\\Server\\EngineTransferTest::completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData';
    $base = engine_verification_memory_report($root, used: 4_194_304);
    $project = static fn (array $report): ?array => \Tablo\Tools\server_native_memory_failure($root, \Tablo\JsonEncoding::encode($report), $identity, ['tests/Server/EngineTransferTest.php']);
    foreach ([[2_097_153, engine_verification_memory_report($root, used: 2_097_153)], [4_194_304, $base],
        [268_435_456, engine_verification_memory_report($root, used: 268_435_456)]] as [$used, $report]) {
        $before = $report; $bytes = \Tablo\JsonEncoding::encode($report);
        $legacy = \Tablo\Tools\server_native_failure($root, $bytes, $identity, ['tests/Server/EngineTransferTest.php']);
        $actual = $project($report);
        Assert::same($actual, ['schema' => 'tablo/server-native-memory-diagnostic/v1', 'unit' => 'bytes',
            'scope' => 'complete-cold-roundtrip-php-working-data', 'rootCause' => 'UNKNOWN', 'peakPhpWorkingBytes' => $used]);
        Assert::same(array_keys($legacy), ['schema', 'rootCause', 'reportStatus', 'type', 'origin', 'source', 'line', 'category', 'callerSource', 'callerLine']);
        Assert::same(\Tablo\Tools\server_native_failure($root, $bytes, $identity, ['tests/Server/EngineTransferTest.php']), $legacy);
        Assert::same($report, $before);
        Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), 'PRIVATE_MEMORY_MESSAGE_SQL_SECRET_HISTORY'));
        Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $root));
        Assert::true(strlen(\Tablo\JsonEncoding::encode($actual)) < 256);
    }
    engine_verification_memory_messages($base, $project);
    engine_verification_memory_origins($root, $base, $project);
    engine_verification_memory_envelope($root, $identity, $base, $project);
}

/** Use the installed assertion formatter; only its synthetic caller frame is supplied explicitly. */
function engine_verification_memory_report(string $root, int $used): array
{
    $failure = null;
    try { Assert::true($used <= 2_097_152, 'Complete measured helper, including cold transfer metadata, exceeds2MiB; peakPhpWorkingBytes=' . $used); }
    catch (\Testo\Assert\State\Assertion\ComparisonFailure $error) { $failure = $error; unset($error); }
    if ($failure === null) { throw new \RuntimeException('Expected synthetic upper-memory assertion.'); }
    return ['status' => 'failed', 'failures' => [['test' => 'Tablo\\Tests\\Server\\EngineTransferTest::completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData',
        'status' => 'failed', 'exception' => $failure::class, 'message' => $failure->getMessage(), 'file' => $failure->getFile(), 'line' => $failure->getLine(),
        'trace' => ['#0 ' . $root . '/tests/Server/EngineTransferTest.php:245: Testo\\Assert::true()', '#1 PRIVATE_MEMORY_MESSAGE_SQL_SECRET_HISTORY'],
        'output' => [['content' => 'PRIVATE_MEMORY_MESSAGE_SQL_SECRET_HISTORY']]]]];
}

function engine_verification_memory_case(array $report): array
{
    $failures = \Tablo\JsonRecord::checked($report['failures'] ?? null);
    return \Tablo\JsonRecord::checked($failures[0] ?? null);
}

/** @param \Closure(array): ?array $project */
function engine_verification_memory_messages(array $report, \Closure $project): void
{
    $failure = engine_verification_memory_case($report); $message = \Tablo\SqlColumn::string($failure, 'message');
    foreach (['0', '2097152', '268435457', '02097153', '+2097153', '-2097153', '2097153.0', '2.097153e6',
        ' 2097153', '2097153 ', "2097153\n", "2097153\0", '9223372036854775808'] as $number) {
        $bad = $report; $bad['failures'] = [array_replace($failure, ['message' => str_replace('4194304', replace: $number, subject: $message)])];
        Assert::null($project($bad));
    }
    foreach (['prefix' . $message, $message . 'suffix', $message . "\n", $message . "\nDetails:\nPRIVATE_MEMORY_MESSAGE_SQL_SECRET_HISTORY",
        str_replace("\n", replace: "\r\n", subject: $message), str_replace('Complete', replace: 'complete', subject: $message),
        str_replace('`false`', replace: '`0`', subject: $message), null, [], 4_194_304] as $invalid) {
        $bad = $report; $bad['failures'] = [array_replace($failure, ['message' => $invalid])];
        Assert::null($project($bad));
    }
}

/** @param \Closure(array): ?array $project */
function engine_verification_memory_origins(string $root, array $report, \Closure $project): void
{
    $failure = engine_verification_memory_case($report);
    foreach (['file' => ['/foreign/vendor/testo/assert/Assert.php', $root . '/vendor/testo/assert/src/Assert.php',
        $root . '/vendor/testo/assert/../Assert.php', null, str_repeat('x', times: 4097)],
        'line' => [0, 100_001, '151', null, true], 'status' => ['error', 'passed'], 'exception' => ['RuntimeException', 'ComparisonFailure'],
        'test' => ['foreign', null]] as $key => $invalids) {
        foreach ($invalids as $invalid) {
            $bad = $report; $bad['failures'] = [array_replace($failure, [$key => $invalid])];
            Assert::null($project($bad));
        }
    }
    engine_verification_memory_frames($root, $report, $project);
    foreach ([1, 100_000] as $line) {
        $bad = $report; $bad['failures'] = [array_replace($failure, ['line' => $line,
            'file' => str_replace('/', replace: '\\', subject: $root . '/vendor/testo/assert/Assert.php'),
            'trace' => ['#0 ' . str_replace('/', replace: '\\', subject: $root . '/tests/Server/EngineTransferTest.php') . ':' . $line . ': Testo\\Assert::true()']])];
        Assert::same($project($bad), $project($report));
    }
}

/** @param \Closure(array): ?array $project */
function engine_verification_memory_frames(string $root, array $report, \Closure $project): void
{
    $failure = engine_verification_memory_case($report); $first = '#0 ' . $root . '/tests/Server/EngineTransferTest.php:245: Testo\\Assert::true()';
    foreach ([[], null, $first, ['foreign' => $first], ['#1 ' . substr($first, offset: 3)], ['PRIVATE_FIRST_FRAME', $first],
        [str_repeat('x', times: 4097)], [1], [null], [str_replace('EngineTransferTest', replace: 'EngineConstraintsTest', subject: $first)],
        [str_replace(':245:', replace: ':0:', subject: $first)], [str_replace(':245:', replace: ':100001:', subject: $first)],
        [str_replace(':245:', replace: ':0245:', subject: $first)], [str_replace('::true()', replace: '::same()', subject: $first)],
        [str_replace('::true()', replace: '::true(PRIVATE_ARGUMENT)', subject: $first)], [$first . 'PRIVATE_SUFFIX'], [$first . "\n"]] as $trace) {
        $bad = $report; $bad['failures'] = [array_replace($failure, ['trace' => $trace])];
        Assert::null($project($bad));
    }
}

/** @param \Closure(array): ?array $project */
function engine_verification_memory_envelope(string $root, string $identity, array $report, \Closure $project): void
{
    $failure = engine_verification_memory_case($report); $bytes = \Tablo\JsonEncoding::encode($report);
    foreach ([['status' => 'passed', 'failures' => [$failure]], ['status' => 'failed', 'failures' => []],
        ['status' => 'failed', 'failures' => [$failure, $failure]], ['status' => 'failed', 'failures' => ['key' => $failure]],
        ['status' => 'failed', 'failures' => [null]]] as $bad) { Assert::null($project($bad)); }
    $source = 'tests/Server/EngineTransferTest.php'; $padding = 1_048_576 - strlen($bytes);
    if ($padding < 0) { throw new \RuntimeException('Synthetic memory report exceeds its fixture bound.'); }
    $raw = /** @param array<int|string, string> $sources */ static fn (string $wire, array $sources): ?array => \Tablo\Tools\server_native_memory_failure($root, $wire, $identity, $sources);
    foreach (['', '{', $bytes . 'false', "\xef\xbb\xbf" . $bytes,
        $bytes . str_repeat(' ', times: $padding + 1),
        substr($bytes, offset: 0, length: -1) . ',"deep":' . str_repeat('[', times: 33) . '0' . str_repeat(']', times: 33) . '}'] as $wire) {
        Assert::null($raw($wire, [$source]));
    }
    Assert::same($raw($bytes . str_repeat(' ', times: $padding), [$source]), $project($report));
    Assert::same($raw($bytes, array_fill(0, count: 2048, value: $source)), $project($report));
    foreach ([[], ['key' => $source], array_fill(0, count: 2049, value: $source)] as $sources) { Assert::null($raw($bytes, $sources)); }
    $injected = $report + ['publicSources' => [$source]]; Assert::null($raw(\Tablo\JsonEncoding::encode($injected), []));
    $foreign = $report; $foreign['failures'] = [array_replace($failure, ['test' => 'foreign'])];
    Assert::null(\Tablo\Tools\server_native_memory_failure($root, \Tablo\JsonEncoding::encode($foreign), identity: 'foreign', publicSources: [$source]));
}

function engine_verification_failed_report_invalid(string $root, string $identity, array $failure, string $private): void
{
    $project = static fn (array $value): array => \Tablo\Tools\server_native_failure($root, \Tablo\JsonEncoding::encode($value), $identity, engine_verification_public_sources());
    foreach (['', '{', str_repeat(' ', times: 1_048_577)] as $invalid) {
        Assert::same(\Tablo\Tools\server_native_failure($root, $invalid, $identity, engine_verification_public_sources())['reportStatus'], 'UNKNOWN');
    }
    foreach ([['status' => 'passed', 'failures' => [$failure]], ['status' => 'failed', 'failures' => []],
        ['status' => 'failed', 'failures' => [$failure, $failure]], ['status' => 'failed', 'failures' => ['foreign' => $failure]],
        ['status' => 'failed', 'failures' => [array_replace($failure, ['test' => 'foreign'])]],
        ['status' => 'failed', 'failures' => [array_replace($failure, ['status' => 'passed'])]]] as $invalid) {
        Assert::same($project($invalid)['reportStatus'], 'UNKNOWN');
    }
    foreach ([['/foreign/app/Private.php', 1], [$root . '/storage/Private.php', 1], [$root . '/app/../Private.php', 1],
        [$root . '/app/DatabaseTransferTarget.php', '1'], [$root . '/app/DatabaseTransferTarget.php', 0], [$root . '/app/DatabaseTransferTarget.php', 100_001], [$private, null]] as [$file, $line]) {
        $bad = array_replace($failure, ['file' => $file, 'line' => $line, 'trace' => [$private]]);
        $actual = $project(['status' => 'failed', 'failures' => [$bad]]);
        Assert::same([$actual['origin'], $actual['source'], $actual['line']], ['UNKNOWN', null, null]);
    }
    engine_verification_failed_report_membership($root, $identity, $failure, $private, $project);
    engine_verification_failed_report_bounds($failure, $private, $project);
}

/** @return list<string> */
function engine_verification_public_sources(): array { return ['app/DatabaseTransfer.php', 'app/DatabaseTransferTarget.php', 'tests/Server/EngineConstraintsTest.php']; }

/** Independent public message fixtures; only the complete exact DatabaseFailure message is admitted.
 * @param \Closure(array): array $project */
function engine_verification_failed_category(array $failure, string $private, \Closure $project): void
{
    $categories = ['Database configuration invalid.' => 'configuration', 'Selected PDO driver unavailable.' => 'driver',
        'Unsupported database engine.' => 'engine', 'Database schema incompatible or in progress.' => 'schema',
        'Installation key unavailable.' => 'key', 'Database busy.' => 'busy', 'Storage outcome uncertain.' => 'uncertain',
        'Database constraint refused.' => 'constraint', 'Database unavailable.' => 'unavailable'];
    foreach ($categories as $message => $category) {
        $value = array_replace($failure, ['exception' => 'Tablo\\DatabaseFailure', 'message' => $message]);
        Assert::same(\Tablo\SqlColumn::string($project(['status' => 'failed', 'failures' => [$value]]), 'category'), $category);
        foreach ([$private . $message, $message . $private, $message . "\n", strtolower($message), null, []] as $invalid) {
            $value['message'] = $invalid;
            Assert::same(\Tablo\SqlColumn::string($project(['status' => 'failed', 'failures' => [$value]]), 'category'), 'UNKNOWN');
        }
    }
    $value = array_replace($failure, ['exception' => 'RuntimeException', 'message' => 'Database busy.']);
    Assert::same(\Tablo\SqlColumn::string($project(['status' => 'failed', 'failures' => [$value]]), 'category'), 'UNKNOWN');
}

/** @param \Closure(array): array $project */
function engine_verification_failed_caller(string $root, array $failure, string $private, \Closure $project): void
{
    $value = array_replace($failure, ['exception' => 'Tablo\\DatabaseFailure', 'message' => 'Database schema incompatible or in progress.',
        'file' => $root . '/app/DatabaseTransfer.php', 'line' => 125,
        'trace' => ['#0 ' . $root . '/app/DatabaseTransfer.php:53: safe(' . $private . ')']]);
    $actual = $project(['status' => 'failed', 'failures' => [$value]]);
    Assert::same([\Tablo\SqlColumn::nullableString($actual, 'source'), \Tablo\SqlColumn::nullableInteger($actual, 'line'),
        \Tablo\SqlColumn::nullableString($actual, 'callerSource'), \Tablo\SqlColumn::nullableInteger($actual, 'callerLine'), \Tablo\SqlColumn::string($actual, 'category')],
        ['app/DatabaseTransfer.php', 125, 'app/DatabaseTransfer.php', 53, 'schema']);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $private));
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $root));
    foreach (['app/' . $private . '.php:53: safe()', 'storage/Private.php:53: safe()', 'app/DatabaseTransfer.php:0: safe()',
        'app/DatabaseTransfer.php:100001: safe()'] as $frame) {
        $value['trace'] = ['#0 ' . $root . '/' . $frame];
        $actual = $project(['status' => 'failed', 'failures' => [$value]]);
        Assert::same([\Tablo\SqlColumn::nullableString($actual, 'callerSource'), \Tablo\SqlColumn::nullableInteger($actual, 'callerLine')], [null, null]);
        Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $private));
    }
    foreach ([[], ['key' => '#0 ' . $root . '/app/DatabaseTransfer.php:53: safe()'],
        [str_repeat('x', times: 4097)], [...array_fill(0, count: 8, value: $private), '#8 ' . $root . '/app/DatabaseTransfer.php:53: safe()']] as $trace) {
        $value['trace'] = $trace;
        $actual = $project(['status' => 'failed', 'failures' => [$value]]);
        Assert::same([\Tablo\SqlColumn::nullableString($actual, 'callerSource'), \Tablo\SqlColumn::nullableInteger($actual, 'callerLine')], [null, null]);
    }
}

/** @param \Closure(array): array $project */
function engine_verification_failed_report_membership(string $root, string $identity, array $failure, string $private, \Closure $project): void
{
    foreach (['app/' . $private . '.php', 'tests/Server/' . $private . '.php'] as $candidate) {
        foreach (['exception', 'trace'] as $kind) {
            $bad = array_replace($failure, ['file' => $root . '/' . $candidate, 'line' => 1, 'trace' => []]);
            if ($kind === 'trace') { $bad['file'] = $root . '/vendor/testo/assert/Assert.php'; $bad['trace'] = ['#0 ' . $root . '/' . $candidate . ':1: private()']; }
            $actual = $project(['status' => 'failed', 'failures' => [$bad]]);
            Assert::null(\Tablo\SqlColumn::nullableString($actual, 'source'));
            Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $private));
        }
    }
    $wire = \Tablo\JsonEncoding::encode(['status' => 'failed', 'failures' => [$failure], 'publicSources' => ['app/' . $private . '.php']]);
    Assert::null(\Tablo\Tools\server_native_failure($root, $wire, $identity, [])['source']);
    Assert::same(\Tablo\Tools\server_native_failure($root, $wire, $identity, array_fill(0, count: 2049, value: 'app/DatabaseTransferTarget.php'))['reportStatus'], 'UNKNOWN');
}

/** @param \Closure(array): array $project */
function engine_verification_failed_report_bounds(array $failure, string $private, \Closure $project): void
{
    $bounded = $failure; $bounded['trace'] = [...array_fill(0, count: 8, value: $private), ...\Tablo\JsonRecord::checked($failure['trace'] ?? null)];
    Assert::null(\Tablo\SqlColumn::nullableString($project(['status' => 'failed', 'failures' => [$bounded]]), 'source'));
    $bounded['trace'] = [str_repeat(' ', times: 4097), [], 1, null];
    Assert::null(\Tablo\SqlColumn::nullableString($project(['status' => 'failed', 'failures' => [$bounded]]), 'source'));
    $bounded['exception'] = $private;
    $actual = $project(['status' => 'failed', 'failures' => [$bounded]]);
    Assert::same(\Tablo\SqlColumn::string($actual, 'type'), 'UNKNOWN'); Assert::false(str_contains(\Tablo\JsonEncoding::encode($actual), $private));
    Assert::true(strlen(\Tablo\JsonEncoding::encode($actual)) < 1024);
}

/** A real failed child and a throwing diagnostic hook must retain the original nonzero failure and closed receipt. */
function engine_verification_failed_hook(): void
{
    $directory = new TemporaryDirectory('tablo-server-failed-hook-');
    $actor = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tools/verify-server.php';
$directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-native-failed-hook-');
$observed = null; $caught = false;
try {
    try { server_verify_child([PHP_BINARY, '-n', '-r', 'exit(7);'], $directory, [], 8,
        static function (array $child) use (&$observed): void { $observed = $child; throw new \RuntimeException('PRIVATE_DIAGNOSTIC_FAILURE'); }); }
    catch (\RuntimeException $error) { $caught = $error->getMessage() === 'Required native child failed or its exit is unknown.'; }
    $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($observed['files']['receipt']);
    echo json_encode(['primaryPreserved' => $caught, 'childExit' => $observed['exit_code'], 'closed' => $receipt['closed'],
        'rootExitObserved' => $receipt['root_exit_observed'], 'receiptExit' => $receipt['exit_code']], JSON_THROW_ON_ERROR);
} finally { $directory->close(); }
PHP;
    try {
        $closure = $directory->path . '/closure';
        if (!mkdir($closure, permissions: 0o700)) { throw new \RuntimeException('Owned publisher closure absent.'); }
        $child = Subprocess::run([PHP_BINARY, '-r', $actor, dirname(__DIR__, levels: 2)], $directory,
            ['TABLO_SESSION_EVIDENCE_DIRECTORY' => $closure]);
        Assert::same($child['exit_code'], 0); Assert::same($child['stderr'], '');
        Assert::same(\Tablo\JsonRecord::decode($child['stdout']), ['primaryPreserved' => true, 'childExit' => 7, 'closed' => true, 'rootExitObserved' => true, 'receiptExit' => 7]);
        engine_verification_r74_publisher($child, $closure);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

/** Reuse the hook's actual stopped outer PHP child and captured exit7 descendant; no additional process is launched.
 * @param \Tablo\Tests\Support\Subprocess::Result $child */
function engine_verification_r74_publisher(array $child, string $closure): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixtureCatalog.php';
    $path = $closure . '/transfer-phase-r74-v1.tsv';
    file_put_contents($path, engine_verification_r74_wire([engine_verification_r74_initial(), [4, 2, 0, 115, 901, 0, 10, 1]]));
    $reads = 0;
    $read = static function () use ($path, &$reads): array {
        $reads++;
        return \Tablo\Tools\server_native_phase_project(\Tablo\RequiredFile::bytes(file_get_contents($path, length: 8193)));
    };
    Assert::true(\Tablo\Tools\server_native_phase_closed($child, $closure));
    Assert::same(\Tablo\SqlColumn::string(\Tablo\Tools\server_native_phase_publish($child, $closure, $read), 'status'), 'APP_FAILURE'); Assert::same($reads, 1);
    $start = engine_verification_r74_closure_record($closure, prefix: 'direct-child-start-');
    $close = engine_verification_r74_closure_record($closure, prefix: 'direct-child-close-');
    engine_verification_r74_missing_descendant($child, $closure, $read, $reads);
    engine_verification_r74_wrong_parent($child, $closure, $read, $reads);
    engine_verification_r74_unproved_descendant($child, $closure, $read, $reads);
    Assert::same(\Tablo\Tools\server_native_phase_publish($child, $closure, static function (): never {
        throw new \RuntimeException('PRIVATE_SYNTHETIC_READER_FAILURE');
    }), \Tablo\Tools\server_native_phase_unknown());
    Assert::same(\Tablo\SqlColumn::string(\Tablo\Tools\server_native_phase_publish($child, $closure, $read), 'status'), 'APP_FAILURE');
    Assert::true(is_file($start) && is_file($close));
}

function engine_verification_r74_closure_record(string $closure, string $prefix): string
{
    $paths = [];
    foreach (new \DirectoryIterator($closure) as $entry) {
        if (!$entry->isFile() || !str_starts_with($entry->getFilename(), $prefix) || !str_ends_with($entry->getFilename(), '.json')) { continue; }
        $paths[] = $entry->getPathname();
    }
    Assert::same(count($paths), 1);
    return $paths[0] ?? throw new \RuntimeException('Actual hook descendant record absent.');
}

/** A ready trace must not be read for a stopped parent with missing/unproven measured-child closure.
 * @param array $child
 * @param \Closure(): array $read */
function engine_verification_r74_publish_refuses(array $child, string $closure, \Closure $read, int &$reads): void
{
    $reads = 0;
    Assert::same(\Tablo\Tools\server_native_phase_publish($child, $closure, $read), \Tablo\Tools\server_native_phase_unknown());
    Assert::same($reads, 0);
}

/** @param array $child
 * @param \Closure(): array $read */
function engine_verification_r74_missing_descendant(array $child, string $closure, \Closure $read, int &$reads): void
{
    $start = engine_verification_r74_closure_record($closure, prefix: 'direct-child-start-');
    $close = engine_verification_r74_closure_record($closure, prefix: 'direct-child-close-');
    Assert::true(rename($close, $close . '.held'));
    try {
        engine_verification_r74_publish_refuses($child, $closure, $read, $reads);
        Assert::true(rename($start, $start . '.held'));
        try { engine_verification_r74_publish_refuses($child, $closure, $read, $reads); }
        finally { rename($start . '.held', $start); }
    } finally { rename($close . '.held', $close); }
}

/** @param array $child
 * @param \Closure(): array $read */
function engine_verification_r74_wrong_parent(array $child, string $closure, \Closure $read, int &$reads): void
{
    $start = engine_verification_r74_closure_record($closure, prefix: 'direct-child-start-');
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($start)); $record = \Tablo\JsonRecord::decode($bytes);
    try {
        foreach ([['controllerPid' => 0], ['ownership' => 'UNKNOWN'], ['pid' => null]] as $changes) {
            file_put_contents($start, \Tablo\JsonEncoding::encode(array_replace($record, $changes)));
            engine_verification_r74_publish_refuses($child, $closure, $read, $reads);
        }
    } finally { file_put_contents($start, $bytes); }
}

/** Actual receipt copies are deliberately corrupted as negative protocol controls, then restored.
 * @param array $child
 * @param \Closure(): array $read */
function engine_verification_r74_unproved_descendant(array $child, string $closure, \Closure $read, int &$reads): void
{
    $close = engine_verification_r74_closure_record($closure, prefix: 'direct-child-close-');
    $closedBytes = \Tablo\RequiredFile::bytes(file_get_contents($close)); $closed = \Tablo\JsonRecord::decode($closedBytes);
    $copy = \Tablo\SqlColumn::string($closed, 'receiptCopy');
    $receiptBytes = \Tablo\RequiredFile::bytes(file_get_contents($copy)); $receipt = \Tablo\JsonRecord::decode($receiptBytes);
    try {
        foreach ([['closed' => false], ['root_exit_observed' => false], ['pid' => 0],
            ['exit_code' => null, 'proc_close' => -1, 'signaled' => false, 'term_signal' => null]] as $changes) {
            $bytes = \Tablo\JsonEncoding::encode(array_replace($receipt, $changes)); file_put_contents($copy, $bytes);
            file_put_contents($close, \Tablo\JsonEncoding::encode(array_replace($closed, ['receiptSha256' => hash('sha256', $bytes)])));
            engine_verification_r74_publish_refuses($child, $closure, $read, $reads);
        }
    } finally { file_put_contents($copy, $receiptBytes); file_put_contents($close, $closedBytes); }
}

/** Independent authorization tuples; do not derive negative controls from the qualifier's generated policy.
 * @return list<array{identity: string, path: string, reason: string}> */
function engine_verification_platform_policies(): array
{
    return [
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperTest::shippedHelperPreservesHostEncodingAndExactCredentials', 'path' => 'tests/Network/PasswordHelperTest.php', 'reason' => 'Native Windows PowerShell host required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureObservationTest::preHelperStallEmitsEntryWhileNativeStagesRemainUnobserved', 'path' => 'tests/Network/PasswordHelperFixtureObservationTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureObservationTest::observerDiagnosticFailurePreservesCallerAndRejectsIncompleteCollection', 'path' => 'tests/Network/PasswordHelperFixtureObservationTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureObservationTest::fixtureReadinessRequiresEntryAndKnownTerminationExit', 'path' => 'tests/Network/PasswordHelperFixtureObservationTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureObservationTest::fixtureAppendCollisionFailsCollectorAndPreservesActualHelperFailure', 'path' => 'tests/Network/PasswordHelperFixtureObservationTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureObservationTest::normalFixtureOrdersNativeEventsWithOriginalCredentialEncodingRepeatAndPrivacyAssertions', 'path' => 'tests/Network/PasswordHelperFixtureObservationTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
        ['identity' => 'Tablo\\Tests\\Network\\PasswordHelperFixtureReadinessTest::fixtureReadinessRequiresMarkerAndEntryWithinOriginalBudget', 'path' => 'tests/Network/PasswordHelperFixtureReadinessTest.php', 'reason' => 'Native Windows PowerShell fixture control required.'],
    ];
}

/** @param list<array{identity: string, path: string, reason: string}> $policies */
function engine_verification_integrated_baseline(array $policies): void
{
    $catalog = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents(dirname(__DIR__, levels: 2) . '/tools/server-native-tests.json')));
    $declaration = \Tablo\JsonRecord::checked($catalog['baseline'] ?? null);
    $required = \Tablo\Tools\server_native_baseline_declaration($declaration);
    Assert::same(count($required), 507);
    foreach ($policies as $policy) { Assert::same($required[$policy['identity']]['path'] ?? null, $policy['path']); }
    $identities = \Tablo\JsonRecord::checked($declaration['identities'] ?? null);
    $variants = [];
    $changed = $declaration; $changed['requiredIdentityCount'] = 441; $variants[] = $changed;
    $changed = $declaration; $changed['identities'] = array_slice($identities, offset: 0, length: -1); $variants[] = $changed;
    $changed = $declaration; $extraIdentity = $identities; $extraIdentity[] = $identities[0] ?? null; $changed['identities'] = $extraIdentity; $variants[] = $changed;
    $changed = $declaration; $duplicateIdentity = $identities; $duplicateIdentity[1] = $identities[0] ?? null; $changed['identities'] = $duplicateIdentity; $variants[] = $changed;
    $changed = $declaration;
    $platform = \Tablo\JsonRecord::checked($changed['platformPolicy'] ?? null);
    $additional = \Tablo\JsonRecord::checked($platform['additionalSkips'] ?? null);
    $additional[] = ['identity' => 'Unknown\\Evidence::skip', 'path' => 'tests/Network/PasswordHelperTest.php', 'reason' => 'Native Windows PowerShell host required.'];
    $platform['additionalSkips'] = $additional; $changed['platformPolicy'] = $platform; $variants[] = $changed;
    foreach ($variants as $invalid) {
        Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_baseline_declaration($invalid)));
    }
}

/** @param array{identity: string, path: string, reason: string} $policy */
function engine_verification_platform_wire(string $root, array $policy): string
{
    $parts = explode('::', $policy['identity']);
    $file = htmlspecialchars($root . '/' . $policy['path'], ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
    return '<testcase classname="' . $parts[0] . '" name="' . ($parts[1] ?? throw new \RuntimeException('Platform method identity absent.')) . '" file="' . $file
        . '" assertions="0" time="0.01" testo:status="skipped"><skipped message="' . $policy['reason'] . '"/></testcase>';
}

/** @param array{identity: string, path: string, reason: string} $policy */
function engine_verification_platform_case(string $root, TemporaryDirectory $directory, array $policy): void
{
    $wire = engine_verification_platform_wire($root, $policy); $report = $directory->path . '/report.xml';
    $spec = ['identity' => $policy['identity'], 'path' => $policy['path'], 'minimumAssertions' => 1, 'deadlineSeconds' => null];
    $required = [$policy['identity'] => $spec];
    engine_verification_report($report, $wire);
    if (PHP_OS_FAMILY === 'Linux') {
        $actual = \Tablo\Tools\server_native_junit($root, $report, $required);
        Assert::same([$actual['platform'], $actual['cases'][$policy['identity']]['status'] ?? null, $actual['assertions']], ['Linux', 'skipped_platform', 0]);
    }
    if (PHP_OS_FAMILY !== 'Linux') { Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required))); }
    $invalid = [str_replace($policy['reason'], replace: 'Different reason', subject: $wire),
        str_replace('assertions="0"', replace: 'assertions="1"', subject: $wire),
        str_replace('assertions="0"', replace: 'assertions="00"', subject: $wire),
        str_replace('time="0.01"', replace: 'time="INF"', subject: $wire),
        str_replace('testo:status="skipped"', replace: 'testo:status="cancelled"', subject: $wire),
        str_replace('</testcase>', replace: '<skipped message="' . $policy['reason'] . '"/></testcase>', subject: $wire),
        str_replace('/></testcase>', replace: '>unexpected</skipped></testcase>', subject: $wire),
        str_replace('<skipped message=', replace: '<wrapper><skipped message=', subject: str_replace('/></testcase>', replace: '/></wrapper></testcase>', subject: $wire))];
    foreach (['failure', 'error', 'flakyFailure', 'flakyError', 'rerunFailure', 'rerunError'] as $tag) {
        $invalid[] = str_replace('</testcase>', replace: '<' . $tag . '/></testcase>', subject: $wire);
    }
    foreach ($invalid as $bytes) {
        engine_verification_report($report, $bytes);
        Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, $required)));
    }
    foreach ([['identity' => $policy['identity'] . 'Unknown'], ['path' => $policy['path'] === 'tests/Network/PasswordHelperTest.php'
        ? 'tests/Network/PasswordHelperFixtureReadinessTest.php' : 'tests/Network/PasswordHelperTest.php']] as $change) {
        $foreign = ['identity' => $change['identity'] ?? $policy['identity'], 'path' => $change['path'] ?? $policy['path'], 'reason' => $policy['reason']];
        $foreignSpec = ['identity' => $foreign['identity'], 'path' => $foreign['path'], 'minimumAssertions' => 1, 'deadlineSeconds' => null];
        engine_verification_report($report, engine_verification_platform_wire($root, $foreign));
        Assert::true(engine_verification_refuses(static fn (): array => \Tablo\Tools\server_native_junit($root, $report, [$foreign['identity'] => $foreignSpec])));
    }
}

/** The aggregate fixture checks an independently specified skip set and summary agreement.
 * @param list<array{identity: string, path: string, reason: string}> $policies */
function engine_verification_baseline_summary(array $policies): void
{
    $cases = [];
    for ($index = 0; $index < 500; $index++) { $cases['Synthetic\\Baseline::case' . $index] = ['status' => 'passed', 'assertions' => 1, 'seconds' => 0.01]; }
    $linux = PHP_OS_FAMILY === 'Linux'; $passed = $linux ? 500 : 507;
    foreach ($policies as $policy) { $cases[$policy['identity']] = ['status' => $linux ? 'skipped_platform' : 'passed', 'assertions' => $linux ? 0 : 1, 'seconds' => 0.01]; }
    $native = ['cases' => $cases, 'assertions' => $passed, 'platform' => PHP_OS_FAMILY];
    $summary = ['status' => 'passed', 'totals' => ['total' => 507, 'passed' => $passed, 'assertions' => $passed, 'skipped' => $linux ? 7 : 0], 'failures' => []];
    \Tablo\Tools\server_native_baseline_summary($summary, $native); Assert::same(count($native['cases']), 507);
    engine_verification_baseline_summary_invalid($summary, $native);
    engine_verification_baseline_skips_invalid($summary, $native, $policies[0]['identity'] ?? throw new \RuntimeException('Independent original platform tuple missing.'));
}

/** @param array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string} $native */
function engine_verification_baseline_summary_invalid(array $summary, array $native): void
{
    foreach (['error' => 1, 'skipped' => 8, 'total' => 441] as $name => $count) {
        $wrong = $summary; $totals = \Tablo\JsonRecord::checked($wrong['totals'] ?? null); $totals[$name] = $count; $wrong['totals'] = $totals; $refused = false;
        try { \Tablo\Tools\server_native_baseline_summary($wrong, $native); } catch (\RuntimeException) { $refused = true; }
        Assert::true($refused);
    }
    $native['platform'] = PHP_OS_FAMILY === 'Linux' ? 'Windows' : 'Linux';
    Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_baseline_summary($summary, $native)));
}

/** @param array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string} $native */
function engine_verification_baseline_skips_invalid(array $summary, array $native, string $first): void
{
    $wrong = $native; $wrong['cases']['Synthetic\\Baseline::case0'] = ['status' => 'skipped_platform', 'assertions' => 0, 'seconds' => 0.01];
    Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_baseline_summary($summary, $wrong)));
    if (PHP_OS_FAMILY === 'Linux') {
        $wrong = $native; $wrong['cases']['Unknown\\Evidence::skip'] = $wrong['cases'][$first] ?? throw new \RuntimeException('Independent original platform case missing.'); unset($wrong['cases'][$first]);
        Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_baseline_summary($summary, $wrong)), 'The correct skip count cannot authorize an unknown replacement identity.');
        $wrong = $native; $wrong['cases'][$first] = ['status' => 'passed', 'assertions' => 1, 'seconds' => 0.01];
        Assert::true(engine_verification_refuses(static fn () => \Tablo\Tools\server_native_baseline_summary($summary, $wrong)), 'Every exact Linux skip must be present.');
    }
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
        engine_verification_initialize_closure($result['files']['receipt']);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

function engine_verification_initialize_closure(string $receipt): void
{
    $native = \Tablo\Tests\Support\SubprocessReceipt::read($receipt);
    Assert::true(is_int($native['pid']) && $native['pid'] > 0);
    Assert::same([$native['exit_code'], $native['root_exit_observed'], $native['closed']], [1, true, true]);
    Assert::same([$native['signaled'] ?? null, $native['term_signal'] ?? null], [false, null]);
    $exits = array_values(array_filter($native['events'], static fn (array $event): bool => $event['stage'] === 'root-exit'));
    $closes = array_values(array_filter($native['events'], static fn (array $event): bool => $event['stage'] === 'proc-close'));
    Assert::same([count($exits), count($closes)], [1, 1]);
    $exit = $exits[0] ?? throw new \RuntimeException('Actual initialization root exit missing.');
    Assert::same([$exit['exit_code'] ?? null, $exit['native_exit_code'] ?? null, $exit['signaled'] ?? null, $exit['signal'] ?? null], [1, 1, false, 0]);
    $close = $closes[0] ?? throw new \RuntimeException('Actual initialization proc-close missing.');
    $raw = \Tablo\SqlColumn::integer($native, 'proc_close');
    Assert::same(\Tablo\SqlColumn::integer($close, 'native_exit_code'), $raw);
    // PHP 8.2 may lose the close return after its actual stopped observation.
    Assert::true($raw === 1 || (PHP_VERSION_ID >= 80_200 && PHP_VERSION_ID < 80_300 && $raw === -1));
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


/** R74 protocol fixtures; these never execute the original actor, PDO, vault, transfer or network. */
function engine_verification_r74(): void
{
    require_once dirname(__DIR__, levels: 2) . '/app/DatabaseTransferSequence.php';
    engine_verification_r74_projection();
    engine_verification_r74_state();
    engine_verification_r74_writer();
    engine_verification_r74_standalone();
    engine_verification_r74_files();
    engine_verification_r74_collector();
}

/** @return list<int> */
function engine_verification_r74_new(): array
{
    return [...range(115, end: 116), ...range(120, end: 128), ...range(130, end: 132), ...range(140, end: 145),
        ...range(150, end: 156), ...range(160, end: 164), ...range(170, end: 172), ...range(180, end: 187)];
}

/** @param list<list<int|string>> $records */
function engine_verification_r74_wire(array $records): string
{
    $wire = '';
    foreach ($records as $record) { $wire .= implode("\t", $record) . "\n"; }
    return $wire;
}

/** @return list<int> */
function engine_verification_r74_initial(): array { return [1, 1, 0, 0, 115, 0, 10, 1]; }

/** @return list<list<int|string>> */
function engine_verification_r74_records(int $phase, int $operation, int $kind = 2): array
{
    $entry = match ($operation) { 0 => 120, 1, 4 => 10, 2 => 12, 3, 6 => 14, 5 => 16, default => throw new \LogicException('Synthetic operation refused.') };
    $previous = match (true) { $phase === 116 => 115, $phase === 120 => 11, default => $entry };
    $record = $kind === 3 ? [3, 2, $operation, $phase, 1, 0, 20, 1] : [2, 2, $operation, $previous, $phase, 0, 20, 1];
    return [engine_verification_r74_initial(), $record, [4, 3, $operation, $phase, 901, 0, 20, 1]];
}

function engine_verification_r74_projection(): void
{
    $unknown = \Tablo\Tools\server_native_phase_unknown();
    Assert::same($unknown, ['schema' => 'tablo/server-transfer-phase-diagnostic/r74-v1', 'scope' => 'instrumented-app-only',
        'status' => 'UNKNOWN', 'rootCause' => 'UNKNOWN', 'originalActorFinalPeak' => 'UNKNOWN', 'tail' => 'UNKNOWN', 'issueAccepted' => false, 'records' => []]);
    Assert::same(count(engine_verification_r74_new()), 43);
    foreach (engine_verification_r74_new() as $phase) {
        Assert::true(\Tablo\database_transfer_phase_known($phase)); Assert::true(\Tablo\Tools\server_native_phase_app($phase));
        engine_verification_r74_phase_projection($phase, $unknown);
    }
    engine_verification_r74_bridges($unknown);
    engine_verification_r74_legacy_contexts($unknown);
    engine_verification_r74_idle_terminals($unknown);
    engine_verification_r74_sparse($unknown);
    engine_verification_r74_malformed($unknown);
    engine_verification_r74_caps($unknown);
    Assert::false(\Tablo\Tools\server_native_phase_integer_bound(1_099_511_627_775));
    Assert::true(\Tablo\Tools\server_native_phase_integer_bound(1_099_511_627_776));
}

/** Legacy facade predecessors retain their own operation domains and the two direct facade bridges.
 * @param array $unknown */
function engine_verification_r74_legacy_contexts(array $unknown): void
{
    foreach (range(10, end: 17) as $phase) {
        foreach (range(0, end: 6) as $operation) {
            $allowed = $operation === 0 && in_array($phase, [11, 13, 15, 17], strict: true);
            $allowed = $allowed || match ($phase) { 10, 11 => in_array($operation, [1, 4], strict: true), 12, 13 => $operation === 2,
                14, 15 => in_array($operation, [3, 6], strict: true), 16, 17 => $operation === 5 };
            foreach ([engine_verification_r74_records($phase, $operation, kind: 3),
                [engine_verification_r74_initial(), [4, 2, $operation, $phase, 901, 0, 10, 1]]] as $records) {
                $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records));
                if ($allowed) { Assert::same($actual['status'], 'APP_FAILURE'); continue; }
                Assert::same($actual, $unknown);
            }
        }
    }
    engine_verification_r74_entry_contexts($unknown);
    engine_verification_r74_late_initial($unknown);
}

/** Idle keeps the last valid facade EXIT; failure/truncation/order terminals must not echo a refused marker.
 * @param array $unknown */
function engine_verification_r74_idle_terminals(array $unknown): void
{
    foreach ([11, 13, 15, 17] as $exit) {
        foreach ([[901, 1, 'APP_FAILURE'], [902, 3, 'TRUNCATED'], [903, 5, 'ORDER_INVALID'], [903, 7, 'ORDER_INVALID']] as [$terminal, $flags, $status]) {
            $records = [engine_verification_r74_initial(), [4, 2, 0, $exit, $terminal, 0, 10, $flags]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], $status);
            $records = [engine_verification_r74_initial(), [3, 2, 0, $exit, 1, 0, 20, $flags], [4, 3, 0, $exit, $terminal, 0, 20, $flags]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], $status);
        }
    }
    engine_verification_r74_idle_witnesses($unknown);
    engine_verification_r74_idle_refusals($unknown);
}

/** The real finite collector helpers leave operations4/5 idle and retain the valid exit when Factory120 is refused.
 * @param array $unknown */
function engine_verification_r74_idle_witnesses(array $unknown): void
{
    foreach ([[4, 11, 33], [5, 17, 71]] as [$completed, $exit, $beforeExit]) {
        $state = engine_verification_r74_machine([...engine_verification_r74_idle_prefix($completed), 120]);
        Assert::same([$state['terminal'], $state['operation'], $state['previous'], $state['flags']], [903, 0, $exit, 5]);
        $sample = [2, 2, $completed, $beforeExit, $exit, 0, 20, 1];
        $last = [4, 3, $state['operation'], $state['previous'], $state['terminal'], 0, 20, $state['flags']];
        $records = [engine_verification_r74_initial(), $sample, $last];
        $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records));
        Assert::same($actual['status'], 'ORDER_INVALID'); Assert::same($actual['records'][2]['previousPhase'] ?? null, $exit);
        Assert::same($actual['records'][2]['phase'], 903);
        $last[1] = 4; $last[6] = 30;
        $records = [engine_verification_r74_initial(), $sample, [3, 3, 0, $exit, 1, 0, 30, 5], $last];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'ORDER_INVALID');
        foreach ([[2, 3, 0, $exit, 120, 0, 30, 1], [2, 3, 0, 120, 130, 0, 30, 1]] as $forbiddenBlock) {
            $records = [engine_verification_r74_initial(), $sample, $forbiddenBlock, [4, 4, 0, 130, 901, 0, 30, 1]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
        }
    }
}

/** @return list<int> */
function engine_verification_r74_idle_prefix(int $completed): array
{
    $prefix = []; $exits = 0;
    foreach (engine_verification_r74_lifecycle() as $phase) {
        $prefix[] = $phase;
        if (!in_array($phase, [11, 13, 15, 17], strict: true)) { continue; }
        $exits++;
        if ($exits === $completed) { return $prefix; }
    }
    throw new \LogicException('Synthetic completed operation absent.');
}

/** @param array $unknown */
function engine_verification_r74_idle_refusals(array $unknown): void
{
    foreach ([10, 12, 14, 16] as $entry) {
        foreach ([[901, 1], [902, 3], [903, 5], [903, 7]] as [$terminal, $flags]) {
            $records = [engine_verification_r74_initial(), [4, 2, 0, $entry, $terminal, 0, 10, $flags]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
            $records = [engine_verification_r74_initial(), [3, 2, 0, $entry, 1, 0, 20, $flags], [4, 3, 0, $entry, $terminal, 0, 20, $flags]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
        }
    }
    foreach ([11, 13, 15, 17] as $exit) {
        foreach ([[900, 1], [901, 3], [901, 5], [902, 1], [902, 5], [903, 1], [903, 3], [903, 4], [903, 6], [120, 5]] as [$terminal, $flags]) {
            $records = [engine_verification_r74_initial(), [4, 2, 0, $exit, $terminal, 0, 10, $flags]];
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
        }
    }
}

/** @param array $unknown */
function engine_verification_r74_entry_contexts(array $unknown): void
{
    foreach ([[11, 120, 0], [13, 120, 0], [15, 120, 0], [11, 16, 5], [17, 14, 6],
        [115, 10, 1], [116, 10, 1], [128, 10, 4], [128, 12, 2], [128, 14, 3]] as [$previous, $phase, $operation]) {
        $records = [engine_verification_r74_initial(), [2, 2, $operation, $previous, $phase, 0, 20, 1],
            [4, 3, $operation, $phase, 901, 0, 20, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'APP_FAILURE');
    }
    foreach ([[11, 12, 2], [14, 10, 1], [17, 16, 5], [20, 10, 1], [20, 12, 2], [20, 14, 6], [20, 16, 5]] as [$previous, $phase, $operation]) {
        $records = [engine_verification_r74_initial(), [2, 2, $operation, $previous, $phase, 0, 20, 1],
            [4, 3, $operation, $phase, 901, 0, 20, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    }
}

/** @param array $unknown */
function engine_verification_r74_late_initial(array $unknown): void
{
    foreach (range(1, end: 6) as $operation) {
        $entry = match ($operation) { 1, 4 => 10, 2 => 12, 3, 6 => 14, 5 => 16 };
        foreach ([115, 116] as $previous) {
            foreach ([[3, 3, 0, $previous, 1, 0, 30, 1], [4, 3, 0, $previous, 901, 0, 30, 1],
                [2, 3, 1, $previous, 10, 0, 30, 1]] as $late) {
                $records = [engine_verification_r74_initial(), [2, 2, $operation, $entry, 20, 0, 20, 1], $late];
                if ($late[0] !== 4) { $records[] = [4, 4, 6, 15, 900, 0, 30, 1]; }
                Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
            }
        }
    }
    $records = [engine_verification_r74_initial(), [2, 2, 0, 120, 121, 0, 20, 1],
        [3, 3, 0, 116, 1, 0, 30, 1], [4, 4, 6, 15, 900, 0, 30, 1]];
    Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
}

/** @param array $unknown */
function engine_verification_r74_phase_projection(int $phase, array $unknown): void
{
    foreach (range(0, end: 6) as $operation) {
        foreach ([false, true] as $predecessor) {
            $records = engine_verification_r74_records($phase, $operation, kind: $predecessor ? 3 : 2);
            $allowed = $phase <= 128 ? $operation === 0 : true;
            if ($phase === 115 && !$predecessor) { $allowed = false; }
            $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records));
            if ($allowed) { Assert::same($actual['status'], 'APP_FAILURE'); Assert::false($actual['issueAccepted']); }
            if (!$allowed) { Assert::same($actual, $unknown); }
        }
    }
    foreach ([false, true] as $predecessor) {
        $records = engine_verification_r74_records($phase, operation: 0, kind: $predecessor ? 3 : 2);
        $records[2] = [4, 3, 0, $phase, 903, 0, 20, 5];
        $allowed = $phase !== 115 || $predecessor;
        $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records));
        if ($allowed) { Assert::same($actual['status'], 'ORDER_INVALID'); }
        if (!$allowed) { Assert::same($actual, $unknown); }
    }
}

/** @param array $unknown */
function engine_verification_r74_bridges(array $unknown): void
{
    foreach ([115, 116] as $previous) {
        $records = [engine_verification_r74_initial(), [2, 2, 1, $previous, 10, 0, 20, 1], [4, 3, 1, 10, 901, 0, 20, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'APP_FAILURE');
    }
    foreach ([[12, 2], [14, 3], [10, 4]] as [$phase, $operation]) {
        $records = [engine_verification_r74_initial(), [2, 2, 0, 120, 128, 0, 20, 1],
            [2, 3, $operation, 128, $phase, 0, 30, 1], [4, 4, $operation, $phase, 901, 0, 30, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'APP_FAILURE');
    }
    foreach ([[12, 2, 115], [14, 3, 116], [16, 5, 128], [14, 6, 128], [12, 2, 132]] as [$phase, $operation, $previous]) {
        $records = [engine_verification_r74_initial(), [2, 2, $operation, $previous, $phase, 0, 20, 1],
            [4, 3, $operation, $phase, 901, 0, 20, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    }
    foreach (range(100, end: 114) as $phase) {
        foreach (range(0, end: 6) as $operation) {
            $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire(engine_verification_r74_records($phase, $operation)));
            if (in_array($operation, [1, 4], strict: true)) { Assert::same($actual['status'], 'APP_FAILURE'); }
            if (!in_array($operation, [1, 4], strict: true)) { Assert::same($actual, $unknown); }
        }
    }
}

/** Normative sparse-order clarification: later observations may skip complete hidden operations.
 * @param array $unknown */
function engine_verification_r74_sparse(array $unknown): void
{
    foreach (range(2, end: 6) as $operation) {
        $entry = match ($operation) { 2 => 12, 3, 6 => 14, 4 => 10, 5 => 16 };
        $records = [engine_verification_r74_initial(), [2, 2, 0, 120, 121, 0, 20, 1],
            [2, 3, $operation, $entry, 20, 0, 30, 1], [4, 4, 6, 15, 900, 0, 30, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'APP_COMPLETE_ONLY');
    }
    $records = [engine_verification_r74_initial(), [2, 2, 0, 120, 121, 0, 20, 1], [4, 3, 6, 15, 900, 0, 20, 1]];
    Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'], 'APP_COMPLETE_ONLY');
    foreach ([901, 902, 903] as $terminal) {
        $flags = match ($terminal) { 901 => 1, 902 => 3, 903 => 5 };
        $records[2] = [4, 3, 0, 121, $terminal, 0, 20, $flags];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records))['status'],
            match ($terminal) { 901 => 'APP_FAILURE', 902 => 'TRUNCATED', 903 => 'ORDER_INVALID' });
    }
    engine_verification_r74_sparse_invalid($unknown);
}

/** @param array $unknown */
function engine_verification_r74_sparse_invalid(array $unknown): void
{
    $records = [engine_verification_r74_initial(), [2, 2, 0, 120, 121, 0, 20, 1],
        [2, 3, 1, 10, 20, 0, 30, 1], [4, 4, 1, 20, 901, 0, 30, 1]];
    Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    foreach ([4, 5, 6] as $operation) {
        $entry = match ($operation) { 4 => 10, 5 => 16, 6 => 14 };
        $records = [engine_verification_r74_initial(), [2, 2, $operation, $entry, 20, 0, 20, 1],
            [2, 3, 0, 120, 121, 0, 30, 1], [4, 4, 0, 121, 901, 0, 30, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    }
    foreach ([2, 3] as $last) {
        $entry = $last === 2 ? 12 : 14;
        $records = [engine_verification_r74_initial(), [2, 2, $last, $entry, 20, 0, 20, 1],
            [2, 3, 0, 120, 121, 0, 30, 1], [2, 4, $last, $entry, 20, 0, 40, 1], [4, 5, 6, 15, 900, 0, 40, 1]];
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    }
}

/** @param array $unknown */
function engine_verification_r74_malformed(array $unknown): void
{
    $records = [engine_verification_r74_initial(), [2, 2, 1, 116, 10, 0, 20, 1], [4, 3, 6, 15, 900, 0, 20, 1]];
    $wire = engine_verification_r74_wire($records);
    foreach (['', rtrim($wire, characters: "\n"), "\xEF\xBB\xBF" . $wire, str_replace("\n", replace: "\r\n", subject: $wire), "\n" . $wire,
        $wire . $wire, $wire . "PRIVATE_SQL_KEY_PATH\n", str_replace("\t20\t", replace: "\t020\t", subject: $wire),
        str_replace("\t20\t", replace: "\tPRIVATE_SQL_KEY_PATH\t", subject: $wire), str_repeat('0', times: 8193),
        str_repeat('1', times: 129) . "\n", "1\t1\t1\t0\t10\t0\t10\t0\n4\t2\t6\t15\t900\t0\t10\t1\n"] as $invalid) {
        Assert::same(\Tablo\Tools\server_native_phase_project($invalid), $unknown);
    }
    foreach ([[0, 1, 2], [0, 7, 0], [1, 0, 1], [1, 1, 3], [1, 2, 7], [1, 3, 117], [1, 4, 119],
        [1, 5, 21], [1, 6, 9], [1, 6, 1_099_511_627_777], [1, 7, 9], [1, 7, 0], [1, 7, 5], [2, 4, 904], [2, 7, 3]] as [$row, $field, $value]) {
        $invalid = $records; $invalid[$row][$field] = $value;
        Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($invalid)), $unknown);
    }
    foreach ([0, 1, 9, 18, 19, 34, 39, 43, 49, 54, 59, 72, 79, 92, 99, 117, 118, 119, 129, 133, 139, 146, 149, 157, 159, 165, 169, 173, 179, 188, 899, 904] as $phase) {
        Assert::false(\Tablo\database_transfer_phase_known($phase));
        foreach ([false, true] as $predecessor) {
            Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire(engine_verification_r74_records($phase, operation: 1, kind: $predecessor ? 3 : 2))), $unknown);
        }
    }
}

/** @param array $unknown */
function engine_verification_r74_caps(array $unknown): void
{
    $records = [engine_verification_r74_initial()];
    for ($index = 2; $index <= 63; $index++) { $records[] = [2, $index, 1, 20, 20, 0, $index + 10, 1]; }
    $records[] = [4, 64, 1, 20, 902, 0, 100, 3];
    $actual = \Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records));
    Assert::same([$actual['status'], count($actual['records'])], ['TRUNCATED', 64]);
    $records[] = [4, 65, 1, 20, 902, 0, 100, 3];
    Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($records)), $unknown);
    $upper = [engine_verification_r74_initial(), [4, 2, 6, 15, 900, 1_099_511_627_776, 1_099_511_627_776, 1]];
    Assert::same(\Tablo\Tools\server_native_phase_project(engine_verification_r74_wire($upper))['status'], 'APP_COMPLETE_ONLY');
}

/** @return list<int> */
function engine_verification_r74_descriptor(): array { return [140, 141, 142, 143, 144, 145]; }

/** @return list<int> */
function engine_verification_r74_metadata(int $version): array
{
    $phases = [150, 130, 131, 132, 151, 152, 153];
    if ($version === 10) { $phases = [...$phases, ...engine_verification_r74_descriptor()]; }
    $phases = [...$phases, 154, 155];
    if ($version === 10) { $phases = [...$phases, 186, ...engine_verification_r74_descriptor(), 183, 184, 185, 187]; }
    return [...$phases, 156];
}

/** @return list<int> */
function engine_verification_r74_table(): array
{
    return [160, ...engine_verification_r74_descriptor(), 161, 162, 170, 171, 172, 180, 183, 184, 185, 181, 182, 163, 164];
}

/** @return list<int> */
function engine_verification_r74_factory(int $slot): array
{
    $phases = [120, 121, ...engine_verification_r74_metadata($slot === 1 ? 0 : 10), 122, 130, 131, 132, 123, 124];
    if ($slot !== 1) { $phases = [...$phases, ...engine_verification_r74_table(), 125, 126]; }
    if ($slot === 3) { $phases[] = 127; }
    return [...$phases, 128];
}

/** Complete numeric lifecycle, not a replacement for the original native scenario.
 * @return list<int> */
function engine_verification_r74_lifecycle(): array
{
    return [115, 116, 10, 20, 21, 22, 23, 24, 100, 101, 105, 106, 107, 108, 40, 140, 141, 142, 143, 41, 42,
        109, 110, 111, 112, 113, 114, 25, 26, 50, 51, 27, 28, 29, 30, 31, 32, 33, 11,
        ...engine_verification_r74_factory(1), 12, 60, 61, 62, 63, 64, 65, 20, 21, 66, 52, 53, 67,
        80, 81, 82, 87, 88, 115, 116, 52, 53, 89, 68, 69, 90, 91, 83, 84, 70, 71, 85, 86, 13,
        ...engine_verification_r74_factory(2), 14, 20, 21, 60, 61, 62, 63, 64, 65, 66, 67, 70, 71, 15,
        ...engine_verification_r74_factory(3), 10, 24, 100, 101, 102, ...engine_verification_r74_table(),
        103, 104, 108, 109, 110, 111, 112, 113, 114, 25, 11, 16, 20, 21, 115, 116, 60, 61, 62, 63, 64, 65,
        66, 67, 70, 71, 17, 14, 20, 21, 60, 61, 62, 63, 64, 65, 66, 67, 70, 71, 15];
}

/** Actual transition helpers, without gate bypass, writer, PDO or memory sampling.
 * @param list<int> $phases
 * @return array{terminal: int, operation: int, completed: int, previous: int, flags: int, initial: int, factory: int, modules: int} */
function engine_verification_r74_machine(array $phases): array
{
    $operation = 0; $lifecycle = 0; $factory = 0; $modules = 0; $previous = 0; $flags = 1; $terminal = 0;
    foreach ($phases as $phase) {
        if (\Tablo\database_transfer_phase_initial_ignored($phase, ($lifecycle >> 4) & 0xF)) { continue; }
        $terminal = \Tablo\database_transfer_phase_transition($phase, $operation, $lifecycle, $factory, $modules);
        if ($terminal === 903) { $flags |= 4; }
        if ($terminal !== 901 && $terminal !== 903) { $previous = $phase; }
        if ($terminal !== 0) { break; }
        \Tablo\database_transfer_phase_leave($phase, $operation, $lifecycle, $factory);
    }
    return ['terminal' => $terminal, 'operation' => $operation, 'completed' => $lifecycle & 0xF, 'previous' => $previous, 'flags' => $flags,
        'initial' => ($lifecycle >> 4) & 0xF, 'factory' => $factory, 'modules' => $modules];
}

function engine_verification_r74_state(): void
{
    $valid = engine_verification_r74_machine(engine_verification_r74_lifecycle());
    Assert::same([$valid['terminal'], $valid['operation'], $valid['previous'], $valid['flags'], $valid['modules']], [900, 6, 15, 1, 0]);
    $gap = engine_verification_r74_machine([115, 116, 10, 11, ...engine_verification_r74_factory(1)]);
    Assert::same([$gap['operation'], $gap['completed'], $gap['factory']], [0, 1, -1]);
    Assert::same(engine_verification_r74_machine([115, 116, 115, 116]), engine_verification_r74_machine([115, 116]));
    foreach ([[115, 115], [115, 10], [115, 116, 12], [115, 116, 10, 12], [115, 116, 10, 11, 12],
        [115, 116, 120], [115, 116, 10, 120], [115, 116, 10, 132],
        [115, 116, 10, 140, 142], [115, 116, 10, 150, 152], [115, 116, 10, 160, 162],
        [115, 116, 10, 170, 172], [115, 116, 10, 180, 182], [115, 116, 10, 183, 185],
        [115, 116, 10, 187], [115, 116, 10, 117], [115, 116, 10, 11, 120, 120],
        [115, 116, 10, 11, 120, 121, 128, 12], [115, 116, 10, 11, 120, 121, 122]] as $invalid) {
        $actual = engine_verification_r74_machine($invalid);
        Assert::same($actual['terminal'], 903); Assert::same($actual['flags'], 5);
    }
    engine_verification_r74_state_gaps();
    engine_verification_r74_optional_scopes();
    engine_verification_r74_nested_scopes();
    engine_verification_r74_cohort();
}

/** Cached clauses, supplied expected tables and empty maintenance metadata have different real paths. */
function engine_verification_r74_optional_scopes(): void
{
    foreach ([130, 140, 150, 160, 170, 180, 183] as $entry) {
        $partial = engine_verification_r74_machine([115, 116, 10, $entry, 901]);
        Assert::same([$partial['terminal'], $partial['flags'], $partial['previous']], [901, 1, $entry]);
    }
    $supplied = [150, 130, 131, 132, 151, 152, 153, 154, 155, 186, 183, 184, 185, 187, 156];
    foreach ([[160, ...engine_verification_r74_descriptor(), 161, ...$supplied, 162, 170, 171, 172, 180, 181, 182, 163, 164],
        [180, ...engine_verification_r74_metadata(10), 181, 182], engine_verification_r74_metadata(0), $supplied] as $phases) {
        $actual = engine_verification_r74_machine([115, 116, 10, ...$phases, 901]);
        Assert::same([$actual['terminal'], $actual['flags'], $actual['modules']], [901, 1, 0]);
    }
}

/** Accepted partial scopes may fail/unwind, but cannot cross or later claim a successful outer return. */
function engine_verification_r74_nested_scopes(): void
{
    foreach ([[140, 130, 141, 142, 143, 131, 132, 11], [130, 140], [170, 180], [180, 170], [183, 140], [186],
        [150, 140], [150, 130, 131, 151], [150, 130, 131, 132, 151, 140],
        [160, 140, 141, 142, 161], [160, ...engine_verification_r74_descriptor(), 161, 170],
        [180, 183, 181], [140, 141, 142, 143, 144, 130]] as $phases) {
        Assert::same(engine_verification_r74_machine([115, 116, 10, ...$phases])['terminal'], 903);
    }
    foreach ([[150, 130, 156], [150, 130, 131, 132, 151, 152, 153, 140, 156], [160, 140, 164],
        [160, ...engine_verification_r74_descriptor(), 161, 150, 130, 156, 164]] as $phases) {
        $actual = engine_verification_r74_machine([115, 116, 10, ...$phases, 901]);
        Assert::same([$actual['terminal'], $actual['flags'], $actual['modules']], [901, 1, 0]);
        Assert::same(engine_verification_r74_machine([115, 116, 10, ...$phases, 11])['terminal'], 903);
    }
    $factory = engine_verification_r74_machine([115, 116, 10, 11, 120, 121, 150, 130, 156, 128, 901]);
    Assert::same([$factory['terminal'], $factory['flags'], $factory['operation'], $factory['factory'], $factory['modules']], [901, 1, 0, -2, 0]);
    $sequential = engine_verification_r74_machine([115, 116, 10, ...engine_verification_r74_descriptor(), 130, 131, 132,
        170, 171, 172, 180, 183, 184, 185, 181, 182, 901]);
    Assert::same([$sequential['terminal'], $sequential['flags'], $sequential['modules']], [901, 1, 0]);
    engine_verification_r74_unwind_parents();
}

function engine_verification_r74_unwind_parents(): void
{
    $check = [115, 116, 10, 180, 150, 130, 156];
    $failed = engine_verification_r74_machine([...$check, 901]);
    Assert::same([$failed['terminal'], $failed['flags'], $failed['modules']], [901, 1, 1 << 24]);
    Assert::same(engine_verification_r74_machine([...$check, 181])['terminal'], 903);
    $table = [115, 116, 10, 160, ...engine_verification_r74_descriptor(), 161, 162, 180, 150, 130, 156, 164];
    $failed = engine_verification_r74_machine([...$table, 901]);
    Assert::same([$failed['terminal'], $failed['flags'], $failed['modules']], [901, 1, 0]);
    Assert::same(engine_verification_r74_machine([...$table, 11])['terminal'], 903);
    $native = engine_verification_r74_machine([115, 116, 10, 11, 120, 121, ...engine_verification_r74_metadata(0), 122, 130, 128, 901]);
    Assert::same([$native['terminal'], $native['flags'], $native['factory'], $native['modules']], [901, 1, -2, 0]);
}

function engine_verification_r74_state_gaps(): void
{
    foreach (range(100, end: 114) as $phase) {
        Assert::same(engine_verification_r74_machine([115, 116, 10, 11, ...engine_verification_r74_factory(1), 12, $phase])['terminal'], 903);
    }
    foreach ([1, 2, 3] as $slot) {
        $prefix = match ($slot) {
            1 => [115, 116, 10, 11],
            2 => [115, 116, 10, 11, ...engine_verification_r74_factory(1), 12, 13],
            3 => [115, 116, 10, 11, ...engine_verification_r74_factory(1), 12, 13, ...engine_verification_r74_factory(2), 14, 15],
        };
        $actual = engine_verification_r74_machine([...$prefix, ...engine_verification_r74_factory($slot)]);
        Assert::same([$actual['operation'], $actual['completed'], $actual['factory']], [0, $slot, -1]);
    }
    $afterThird = [115, 116, 10, 11, ...engine_verification_r74_factory(1), 12, 13,
        ...engine_verification_r74_factory(2), 14, 15, ...engine_verification_r74_factory(3), 10, 11];
    Assert::same(engine_verification_r74_machine([...$afterThird, 120])['terminal'], 903);
    Assert::same(engine_verification_r74_machine([...$afterThird, 16, 17, 120])['terminal'], 903);
 }

function engine_verification_r74_cohort(): void
{
    foreach (['mysql', 'mariadb'] as $engine) {
        foreach ([80_200, 80_299, 80_500, 80_599] as $version) { Assert::true(\Tablo\database_transfer_phase_cohort('cli', platform: 'Linux', version: $version, engine: $engine)); }
        foreach ([80_199, 80_300, 80_400, 80_600] as $version) { Assert::false(\Tablo\database_transfer_phase_cohort('cli', platform: 'Linux', version: $version, engine: $engine)); }
        Assert::false(\Tablo\database_transfer_phase_cohort('cli', platform: 'Windows', version: 80_500, engine: $engine));
        Assert::false(\Tablo\database_transfer_phase_cohort('fpm-fcgi', platform: 'Linux', version: 80_500, engine: $engine));
    }
    Assert::false(\Tablo\database_transfer_phase_cohort('cli', platform: 'Linux', version: 80_500, engine: 'sqlite'));
    Assert::same(\Tablo\database_transfer_phase_terminal(901, flags: 3), 902);
    Assert::same(\Tablo\database_transfer_phase_terminal(900, flags: 7), 903);
}

function engine_verification_r74_writer(): void
{
    $directory = new TemporaryDirectory('tablo-r74-writer-'); $handle = null;
    try {
        $path = $directory->path . '/numeric.tsv'; $handle = fopen($path, mode: 'xb');
        if (!is_resource($handle)) { throw new \RuntimeException('Synthetic writer resource absent.'); }
        $flags = 1;
        Assert::true(\Tablo\database_transfer_phase_emit($handle, kind: 1, fields: "0\t0\t115\t0\t10\t1", flags: $flags));
        for ($index = 1; $index <= 70; $index++) {
            Assert::true(\Tablo\database_transfer_phase_emit($handle, kind: 2, fields: "1\t20\t20\t0\t" . ($index + 10) . "\t" . $flags, flags: $flags));
        }
        Assert::same($flags, 3);
        Assert::true(\Tablo\database_transfer_phase_emit($handle, kind: 4, fields: "1\t20\t902\t0\t100\t3", flags: $flags));
        \Tablo\database_transfer_phase_close($handle); $handle = null;
        $bytes = \Tablo\RequiredFile::bytes(file_get_contents($path));
        Assert::true(strlen($bytes) <= 8192);
        $projected = \Tablo\Tools\server_native_phase_project($bytes);
        Assert::same([$projected['status'], count($projected['records'])], ['TRUNCATED', 64]);
        $handle = fopen($path, mode: 'rb');
        if (!is_resource($handle)) { throw new \RuntimeException('Synthetic read-only resource absent.'); }
        $warnings = 0; $handler = static function () use (&$warnings): bool { $warnings++; return true; };
        set_error_handler($handler);
        try {
            Assert::same(\Tablo\database_transfer_phase_record($handle, kind: 1, sequence: 1, fields: "0\t0\t115\t0\t10\t1"), 0);
            \Tablo\database_transfer_phase_close($handle); $handle = null;
            $restored = set_error_handler($handler); restore_error_handler();
            Assert::same($restored, $handler); Assert::same($warnings, 0);
        } finally { restore_error_handler(); }
        Assert::same(file_get_contents($path), $bytes);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { \Tablo\database_transfer_phase_close($handle); $directory->close(); }
}

/** Exactly two declarations-only PHP controls; the real cold actor is never invoked. */
function engine_verification_r74_standalone(): void
{
    $directory = new TemporaryDirectory('tablo-r74-declarations-');
    try {
        foreach (['preflight', 'sequence'] as $variant) {
            $module = $variant === 'preflight' ? 'DatabaseTransferPreflight' : 'DatabaseTransferSequence';
            $call = $variant === 'preflight' ? '\\Tablo\\database_transfer_preflight_phase(100);' : '\\Tablo\\database_transfer_phase_mark(116);';
            $source = '<?php require ' . var_export(dirname(__DIR__, levels: 2) . '/app/' . $module . '.php', return: true) . ';'
                . '$primary = new \\RuntimeException("SYNTHETIC_PRIMARY"); try { ' . $call . ' throw $primary; } catch (\\Throwable $caught) {'
                . 'echo json_encode(["primaryPreserved"=>$caught === $primary,"facadeAbsent"=>!class_exists("Tablo\\\\DatabaseTransfer",false),'
                . '"observerPresent"=>function_exists("Tablo\\\\database_transfer_phase_mark")],JSON_THROW_ON_ERROR); }';
            $path = $directory->path . '/' . $variant . '.php'; file_put_contents($path, $source);
            $child = Subprocess::run([PHP_BINARY, '-n', $path], $directory, [], new CommandOptions(timeout: 2));
            Assert::same([$child['exit_code'], $child['stderr']], [0, '']);
            Assert::same(\Tablo\JsonRecord::decode($child['stdout']), ['primaryPreserved' => true, 'facadeAbsent' => true, 'observerPresent' => $variant === 'sequence']);
            Assert::false(file_exists($directory->path . '/transfer-phase-r74-v1.tsv'));
        }
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

/** POSIX mode/inode file controls are not claimed on the Windows host. */
function engine_verification_r74_files(): void
{
    if (PHP_OS_FAMILY !== 'Linux') { return; }
    $directory = new TemporaryDirectory('tablo-r74-files-');
    try {
        $wire = engine_verification_r74_wire([engine_verification_r74_initial(), [4, 2, 0, 115, 901, 0, 10, 1]]);
        $path = $directory->path . '/transfer-phase-r74-v1.tsv'; file_put_contents($path, $wire); chmod($path, permissions: 0o600);
        Assert::same(\Tablo\Tools\server_native_phase_bytes($path), $wire);
        chmod($path, permissions: 0o644); Assert::null(\Tablo\Tools\server_native_phase_bytes($path));
        chmod($path, permissions: 0o600); $link = $directory->path . '/linked'; Assert::true(symlink($path, $link));
        Assert::null(\Tablo\Tools\server_native_phase_bytes($link)); Assert::same(file_get_contents($path), $wire);
        $hard = $directory->path . '/hard'; Assert::true(link($path, $hard));
        Assert::null(\Tablo\Tools\server_native_phase_bytes($path)); Assert::same(file_get_contents($path), $wire);
        $oversize = $directory->path . '/oversize'; file_put_contents($oversize, str_repeat('x', times: 8193)); chmod($oversize, permissions: 0o600);
        Assert::null(\Tablo\Tools\server_native_phase_bytes($oversize));
        engine_verification_r74_file_replacement($directory->path, $wire);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { $directory->close(); }
}

/** The actual descriptor must refuse a replacement inode and changing bytes between its two reads. */
function engine_verification_r74_file_replacement(string $directory, string $wire): void
{
    $path = $directory . '/replace.tsv'; file_put_contents($path, $wire); chmod($path, permissions: 0o600);
    $before = lstat($path); $handle = fopen($path, mode: 'rb');
    if (!is_array($before) || !is_resource($handle)) { throw new \RuntimeException('Synthetic stable descriptor absent.'); }
    try {
        Assert::true(rename($path, $path . '.old')); file_put_contents($path, $wire); chmod($path, permissions: 0o600);
        Assert::null(\Tablo\Tools\server_native_phase_descriptor($handle, $path, $before));
        Assert::same(file_get_contents($path . '.old'), $wire); Assert::same(file_get_contents($path), $wire);
    } finally { fclose($handle); }
    $handle = fopen($path, mode: 'rb');
    if (!is_resource($handle)) { throw new \RuntimeException('Synthetic changing descriptor absent.'); }
    try {
        $opened = fstat($handle);
        if (!is_array($opened)) { throw new \RuntimeException('Synthetic opened stat absent.'); }
        Assert::same(stream_get_contents($handle, length: 8193), $wire); Assert::true(rewind($handle));
        file_put_contents($path, str_replace('115', replace: '116', subject: $wire));
        Assert::false(\Tablo\Tools\server_native_phase_stable($handle, $path, $opened, $wire));
    } finally { fclose($handle); }
}

/** Actual Linux cohort children are added below; unsupported hosts never bypass the runtime gate. */
function engine_verification_r74_collector(): void
{
    if (PHP_OS_FAMILY !== 'Linux' || !in_array(intdiv(PHP_VERSION_ID, num2: 100), [802, 805], strict: true)) { return; }
    foreach (['mysql', 'mariadb'] as $engine) {
        engine_verification_r74_collector_case($engine);
    }
}



/** Each layout is owned synthetic protocol data, never the original actor or an engine fixture. */
function engine_verification_r74_collector_case(string $engine): void
{
    foreach (['valid', 'failure', 'gap-failure', 'order', 'bad-id', 'bad-op', 'partial', 'truncated', 'short-write', 'partial-write', 'flush-failure', 'close-failure', 'buffer-unavailable',
        'nonce', 'source', 'script', 'case', 'arguments', 'engine', 'hash', 'plan', 'sink', 'runtime', 'collision', 'linked', 'ancestor'] as $variant) {
        $directory = new TemporaryDirectory('tablo-r74-collector-');
        try { engine_verification_r74_collector_variant($directory, $engine, $variant); }
        catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }
}

/** @return array{source: string, closure: string, environment: array<string, string>, actor: string, foreign: string} */
function engine_verification_r74_layout(TemporaryDirectory $directory, string $engine, string $variant): array
{
    $nonce = str_repeat('a', times: 32); $runtime = str_replace('\\', replace: '/', subject: $directory->path) . '/tablo-server-' . $nonce;
    $source = $runtime . '/source'; $base = $runtime . '/cases/main-0'; $closure = $base . '/closure';
    $fixture = $base . '/' . $engine . '/tablo-server-' . $nonce; $foreign = str_replace('\\', replace: '/', subject: $directory->path) . '/owned-sentinel';
    foreach ([$source . '/app', $source . '/tests', $runtime . '/evidence', $closure, $foreign] as $path) {
        if (!mkdir($path, permissions: 0o700, recursive: true)) { throw new \RuntimeException('Synthetic owned layout absent.'); }
    }
    if ($variant === 'ancestor') { Assert::true(symlink($foreign, $base . '/' . $engine)); }
    if (!mkdir($fixture, permissions: 0o700, recursive: true)) { throw new \RuntimeException('Synthetic fixture directory absent.'); }
    Assert::true(copy(dirname(__DIR__, levels: 2) . '/app/DatabaseTransferSequence.php', $source . '/app/DatabaseTransferSequence.php'));
    $plan = $runtime . '/evidence/started-cell.json'; file_put_contents($plan, data: 'synthetic-guard-bytes-only'); chmod($plan, permissions: 0o600);
    $environment = ['TABLO_TEST_DB_DRIVER' => $engine, 'TABLO_TEST_ENGINE_CASE' => 'transfer-working-data',
        'TABLO_TEST_SOURCE_ROOT' => $source, 'TABLO_TEST_RUN_NONCE' => $nonce, 'TABLO_TEST_RUNTIME_ROOT' => $fixture,
        'TABLO_TEST_CELL_PLAN' => $plan, 'TABLO_TEST_CELL_PLAN_SHA256' => hash('sha256', data: 'synthetic-guard-bytes-only'),
        'TABLO_SESSION_EVIDENCE_DIRECTORY' => $closure];
    $actor = $source . '/tests/' . ($variant === 'script' ? 'other.php' : 'server-child.php');
    return ['source' => $source, 'closure' => $closure, 'environment' => $environment, 'actor' => $actor, 'foreign' => $foreign];
}

/** @param array{source: string, closure: string, environment: array<string, string>, actor: string, foreign: string} $layout
 * @return array<string, string> */
function engine_verification_r74_environment(array $layout, string $variant): array
{
    $environment = $layout['environment']; $foreign = $layout['foreign'];
    $changes = ['nonce' => ['TABLO_TEST_RUN_NONCE', str_repeat('b', times: 32)], 'source' => ['TABLO_TEST_SOURCE_ROOT', $foreign],
        'case' => ['TABLO_TEST_ENGINE_CASE', 'foreign'], 'engine' => ['TABLO_TEST_DB_DRIVER', 'sqlite'],
        'hash' => ['TABLO_TEST_CELL_PLAN_SHA256', str_repeat('0', times: 64)], 'plan' => ['TABLO_TEST_CELL_PLAN', $foreign],
        'sink' => ['TABLO_SESSION_EVIDENCE_DIRECTORY', $foreign], 'runtime' => ['TABLO_TEST_RUNTIME_ROOT', $foreign]];
    if (array_key_exists($variant, $changes)) { [$key, $value] = $changes[$variant]; $environment[$key] = $value; }
    return $environment;
}

function engine_verification_r74_positive(string $variant): bool
{
    return in_array($variant, ['valid', 'failure', 'gap-failure', 'order', 'bad-id', 'bad-op', 'partial', 'truncated', 'short-write', 'partial-write', 'flush-failure', 'close-failure', 'buffer-unavailable'], strict: true);
}

function engine_verification_r74_actor(string $variant): string
{
    $phases = match ($variant) {
        'failure' => [115, 116, 10, 901], 'gap-failure' => [115, 116, 10, 11, 120, 901], 'order' => [115, 116, 10, 12],
        'bad-id' => [115, 116, 10, 117], 'bad-op' => [115, 116, 10, 11, ...engine_verification_r74_factory(1), 12, 100],
        'partial' => [115, 116, 10], default => engine_verification_r74_lifecycle(),
    };
    $namespace = match ($variant) {
        'short-write' => 'namespace Tablo { function fwrite(mixed $handle, string $line): int|false { $GLOBALS["r74Writes"]=($GLOBALS["r74Writes"]??0)+1; return 0; } } namespace {',
        'partial-write' => 'namespace Tablo { function fwrite(mixed $handle, string $line): int|false { $GLOBALS["r74Writes"]=($GLOBALS["r74Writes"]??0)+1; return \fwrite($handle,substr($line,0,-1)); } } namespace {',
        'flush-failure' => 'namespace Tablo { function fwrite(mixed $handle, string $line): int|false { $GLOBALS["r74Writes"]=($GLOBALS["r74Writes"]??0)+1; return \fwrite($handle,$line); } function fflush(mixed $handle): bool { return false; } } namespace {',
        'close-failure' => 'namespace Tablo { function fclose(mixed $handle): bool { throw new \RuntimeException("SYNTHETIC_CLOSE"); } } namespace {',
        'buffer-unavailable' => 'namespace Tablo { function stream_set_write_buffer(mixed $handle, int $size): int { return -1; } } namespace {',
        default => 'namespace {',
    };
    $growth = $variant === 'truncated' ? 'if ($phase === 10 && !$grew) { $grew=true; for($index=1;$index<=70;$index++){ $growth=str_repeat("x",$index*65536); \Tablo\database_transfer_phase_mark(20); }}' : '';
    $facts = engine_verification_r74_positive($variant)
        ? '$facts=["gate"=>\Tablo\database_transfer_phase_gate(),"source"=>\Tablo\database_transfer_phase_source()===str_replace("\\\\","/",dirname(__DIR__)),"derivedDirectory"=>\Tablo\database_transfer_phase_directory()===getenv("TABLO_SESSION_EVIDENCE_DIRECTORY")];'
        : '$facts=[];';
    if (in_array($variant, ['short-write', 'partial-write', 'flush-failure'], strict: true)) { $facts .= '$facts["writeCalls"]=$GLOBALS["r74Writes"]??0;'; }
    return '<?php ' . $namespace . ' require dirname(__DIR__)."/app/DatabaseTransferSequence.php"; $grew=false;'
        . '$primary=new \RuntimeException("SYNTHETIC_PRIMARY");try { foreach(' . var_export($phases, return: true) . ' as $phase){ \Tablo\database_transfer_phase_mark($phase); ' . $growth . ' } throw $primary; }'
        . 'catch(\Throwable $caught){ ' . $facts . ' echo json_encode(["primaryPreserved"=>$caught===$primary]+$facts,JSON_THROW_ON_ERROR); } }';
}

function engine_verification_r74_collector_variant(TemporaryDirectory $directory, string $engine, string $variant): void
{
    $layout = engine_verification_r74_layout($directory, $engine, $variant);
    $trace = $layout['closure'] . '/transfer-phase-r74-v1.tsv'; $sentinel = 'SYNTHETIC_EXISTING_MUST_STAY';
    if ($variant === 'collision') { file_put_contents($trace, $sentinel); chmod($trace, permissions: 0o600); }
    if ($variant === 'linked') { file_put_contents($layout['foreign'] . '/sentinel', $sentinel); Assert::true(symlink($layout['foreign'] . '/sentinel', $trace)); }
    file_put_contents($layout['actor'], engine_verification_r74_actor($variant));
    $arguments = $variant === 'arguments' ? ['foreign', 'transfer-working-data'] : ['transfer-working-data', 'transfer-working-data'];
    $child = Subprocess::run([PHP_BINARY, '-n', $layout['actor'], ...$arguments], $directory, [],
        new CommandOptions(timeout: 2, replacementEnvironment: engine_verification_r74_environment($layout, $variant)));
    Assert::same([$child['exit_code'], $child['stderr']], [0, '']);
    $expected = ['primaryPreserved' => true];
    if (engine_verification_r74_positive($variant)) { $expected += ['gate' => true, 'source' => true, 'derivedDirectory' => true]; }
    if (in_array($variant, ['short-write', 'partial-write', 'flush-failure'], strict: true)) { $expected['writeCalls'] = 1; }
    Assert::same(\Tablo\JsonRecord::decode($child['stdout']), $expected);
    Assert::false(file_exists($layout['foreign'] . '/transfer-phase-r74-v1.tsv'));
    engine_verification_r74_collector_result($trace, $variant, $sentinel);
    if (file_exists($layout['foreign'] . '/sentinel')) { Assert::same(file_get_contents($layout['foreign'] . '/sentinel'), $sentinel); }
    Assert::false(\Tablo\Tools\server_native_phase_actor_source($layout['source']), 'Path-shaped protocol actors cannot qualify the original actor.');
}

function engine_verification_r74_collector_result(string $trace, string $variant, string $sentinel): void
{
    if ($variant === 'collision' || $variant === 'linked') { Assert::same(file_get_contents($trace), $sentinel); return; }
    if (!engine_verification_r74_positive($variant)) { Assert::false(file_exists($trace)); return; }
    $stat = lstat($trace);
    Assert::true(is_array($stat)); Assert::false(is_link($trace));
    Assert::same(($stat['mode'] ?? 0) & 0o777, 0o600); Assert::same($stat['nlink'] ?? null, 1);
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($trace, length: 8193));
    Assert::true(strlen($bytes) <= 8192);
    $actual = \Tablo\Tools\server_native_phase_project($bytes);
    $status = match ($variant) {
        'failure', 'gap-failure' => 'APP_FAILURE', 'order', 'bad-id', 'bad-op' => 'ORDER_INVALID',
        'partial', 'short-write', 'partial-write', 'flush-failure' => 'UNKNOWN', 'truncated' => 'TRUNCATED', default => 'APP_COMPLETE_ONLY',
    };
    Assert::same($actual['status'], $status); Assert::false($actual['issueAccepted']);
    if ($status !== 'UNKNOWN') { Assert::true(count($actual['records']) >= 2 && count($actual['records']) <= 64); }
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
