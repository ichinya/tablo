<?php
declare(strict_types=1);

namespace Tablo\Tools;

use DOMDocument;
use DOMElement;
use RuntimeException;
use Tablo\DatabaseInteger;
use Tablo\JsonRecord;
use Tablo\SqlColumn;

/** Diagnostic projection of one failed Testo report; never evidence of admission or a root cause.
 * @param array<int|string, string> $publicSources Frozen owner catalog, never supplied by the child report; list shape is checked here.
 * @return array{schema: string, rootCause: string, reportStatus: string, type: string, origin: string, source: ?string, line: ?int, category: string, callerSource: ?string, callerLine: ?int} */
function server_native_failure(string $root, #[\SensitiveParameter] string $bytes, string $identity, array $publicSources): array
{
    $unknown = ['schema' => 'tablo/server-native-failure/v2', 'rootCause' => 'UNKNOWN', 'reportStatus' => 'UNKNOWN',
        'type' => 'UNKNOWN', 'origin' => 'UNKNOWN', 'source' => null, 'line' => null,
        'category' => 'UNKNOWN', 'callerSource' => null, 'callerLine' => null];
    try {
        if (strlen($bytes) > 1_048_576 || !array_is_list($publicSources) || count($publicSources) > 2048) { return $unknown; }
        $report = JsonRecord::decode($bytes, 32); $failures = JsonRecord::checked($report['failures'] ?? null);
        if (($report['status'] ?? null) !== 'failed' || !array_is_list($failures) || count($failures) !== 1) { return $unknown; }
        $failure = JsonRecord::checked($failures[0]);
        if (($failure['test'] ?? null) !== $identity || !in_array($failure['status'] ?? null, ['failed', 'error'], strict: true)) { return $unknown; }
        $type = match ($failure['exception'] ?? null) {
            'Testo\\Assert\\State\\Assertion\\ComparisonFailure' => 'assertion', 'Tablo\\DatabaseFailure' => 'database',
            'PDOException' => 'native-database', 'RuntimeException' => 'runtime', 'LogicException' => 'logic',
            'Error', 'TypeError', 'ValueError', 'ParseError' => 'php', default => 'UNKNOWN',
        };
        $location = server_native_failure_location($root, $failure, $publicSources);
        $caller = server_native_failure_trace($root, $failure, $publicSources);
        return ['schema' => 'tablo/server-native-failure/v2', 'rootCause' => 'UNKNOWN', 'reportStatus' => SqlColumn::string($failure, 'status'),
            'type' => $type, 'origin' => $location['origin'], 'source' => $location['source'], 'line' => $location['line'],
            'category' => server_native_failure_category($failure), 'callerSource' => $caller['source'], 'callerLine' => $caller['line']];
    } catch (\Throwable) { return $unknown; }
}

/** One reported assertion value only; neither independent measurement nor acceptance/provenance evidence.
 * @param array<int|string, string> $publicSources Frozen owner catalog, never child-provided source membership.
 * @return array{schema: string, unit: string, scope: string, rootCause: string, peakPhpWorkingBytes: int}|null */
function server_native_memory_failure(string $root, #[\SensitiveParameter] string $bytes, string $identity, array $publicSources): ?array
{
    try {
        if (strlen($bytes) > 1_048_576 || !array_is_list($publicSources) || count($publicSources) > 2048
            || $identity !== 'Tablo\\Tests\\Server\\EngineTransferTest::completeNativeRoundTripOverEightMiBUsesAtMostTwoMiBOfPhpWorkingData') { return null; }
        $failure = server_native_memory_report($bytes, $identity);
        if ($failure === null || !server_native_memory_origin($root, $failure, $publicSources)) { return null; }
        $value = server_native_memory_bytes($failure['message'] ?? null);
        if ($value === null) { return null; }
        return ['schema' => 'tablo/server-native-memory-diagnostic/v1', 'unit' => 'bytes',
            'scope' => 'complete-cold-roundtrip-php-working-data', 'rootCause' => 'UNKNOWN', 'peakPhpWorkingBytes' => $value];
    } catch (\Throwable) { return null; }
}

/** Internal pure report validation; decoding failures stay inside the caller's nullable boundary. */
function server_native_memory_report(#[\SensitiveParameter] string $bytes, string $identity): ?array
{
    $report = JsonRecord::decode($bytes, depth: 32); $failures = JsonRecord::checked($report['failures'] ?? null);
    if (($report['status'] ?? null) !== 'failed' || !array_is_list($failures) || count($failures) !== 1) { return null; }
    $failure = JsonRecord::checked($failures[0]);
    return [$failure['test'] ?? null, $failure['status'] ?? null, $failure['exception'] ?? null]
        === [$identity, 'failed', 'Testo\\Assert\\State\\Assertion\\ComparisonFailure'] ? $failure : null;
}

/** The exact exception file and first caller frame are labels in the report, never paths to open.
 * @param list<string> $publicSources */
function server_native_memory_origin(string $root, #[\SensitiveParameter] array $failure, array $publicSources): bool
{
    /** @var mixed $file Untrusted exception file is checked below. */
    $file = $failure['file'] ?? null;
    /** @var mixed $line Untrusted line must be a bounded integer. */
    $line = $failure['line'] ?? null;
    /** @var mixed $trace Only a list may supply the first caller frame. */
    $trace = $failure['trace'] ?? null;
    if (!is_string($file) || strlen($file) > 4096 || !is_int($line) || $line < 1 || $line > 100_000
        || str_replace('\\', replace: '/', subject: $file) !== str_replace('\\', replace: '/', subject: $root) . '/vendor/testo/assert/Assert.php'
        || !is_array($trace) || !array_is_list($trace)) { return false; }
    /** @var mixed $frame No later frame can replace an invalid first frame. */
    $frame = $trace[0] ?? null; $match = [];
    if (!is_string($frame) || strlen($frame) > 4096
        || preg_match('~\A#0 (.+):([1-9][0-9]{0,5}): Testo\\\\Assert::true\(\)\z~D', $frame, $match) !== 1) { return false; }
    $caller = server_native_failure_origin($root, \Tablo\RegexCapture::text($match, 1), (int) \Tablo\RegexCapture::text($match, 2), $publicSources);
    return $caller['source'] === 'tests/Server/EngineTransferTest.php';
}

function server_native_memory_bytes(#[\SensitiveParameter] mixed $message): ?int
{
    $match = [];
    if (!is_string($message) || preg_match('/\AFailed assertion that `false` is exactly `true`: expected `true`, got `false`\.\nMeaning: Complete measured helper, including cold transfer metadata, exceeds2MiB; peakPhpWorkingBytes=([1-9][0-9]{0,8})\z/D', $message, $match) !== 1) { return null; }
    $value = (int) \Tablo\RegexCapture::text($match, 1);
    return $value > 2_097_152 && $value <= 268_435_456 ? $value : null;
}

/** Only a repository-relative PHP source and line survive; no message, frame text or origin is opened.
 * @param list<string> $publicSources
 * @return array{origin: string, source: ?string, line: ?int} */
function server_native_failure_location(string $root, #[\SensitiveParameter] array $failure, array $publicSources): array
{
    $direct = server_native_failure_origin($root, $failure['file'] ?? null, $failure['line'] ?? null, $publicSources);
    if ($direct['source'] !== null) { return ['origin' => 'exception'] + $direct; }
    return server_native_failure_trace($root, $failure, $publicSources);
}

/** One bounded public caller is diagnostic only; its frame text and arguments are never retained.
 * @param list<string> $publicSources
 * @return array{origin: string, source: ?string, line: ?int} */
function server_native_failure_trace(string $root, #[\SensitiveParameter] array $failure, array $publicSources): array
{
    /** @var mixed $trace Untrusted report data is admitted only as a bounded list below. */
    $trace = $failure['trace'] ?? null;
    if (is_array($trace) && array_is_list($trace)) {
        foreach (array_keys(array_slice($trace, offset: 0, length: 8)) as $index) {
            /** @var mixed $frame No frame is retained before its type, length and prefix checks. */
            $frame = $trace[$index] ?? null;
            $match = [];
            if (!is_string($frame) || strlen($frame) > 4096 || preg_match('/\A#[0-9]{1,3} (.+):([1-9][0-9]{0,5}): /D', $frame, $match) !== 1) { continue; }
            $origin = server_native_failure_origin($root, \Tablo\RegexCapture::text($match, 1), (int) \Tablo\RegexCapture::text($match, 2), $publicSources);
            if ($origin['source'] !== null) { return ['origin' => 'trace'] + $origin; }
        }
    }
    return ['origin' => 'UNKNOWN', 'source' => null, 'line' => null];
}

/** Match complete fixed public DatabaseFailure messages, never a native or partial diagnostic. */
function server_native_failure_category(#[\SensitiveParameter] array $failure): string
{
    if (($failure['exception'] ?? null) !== 'Tablo\\DatabaseFailure') { return 'UNKNOWN'; }
    return match ($failure['message'] ?? null) {
        'Database configuration invalid.' => 'configuration', 'Selected PDO driver unavailable.' => 'driver',
        'Unsupported database engine.' => 'engine', 'Database schema incompatible or in progress.' => 'schema',
        'Installation key unavailable.' => 'key', 'Database busy.' => 'busy', 'Storage outcome uncertain.' => 'uncertain',
        'Database constraint refused.' => 'constraint', 'Database unavailable.' => 'unavailable', default => 'UNKNOWN',
    };
}

/** @param list<string> $publicSources
 * @return array{source: ?string, line: ?int} */
function server_native_failure_origin(string $root, #[\SensitiveParameter] mixed $file, mixed $line, array $publicSources): array
{
    $prefix = str_replace('\\', replace: '/', subject: $root) . '/';
    $path = is_string($file) && strlen($file) <= 4096 ? str_replace('\\', replace: '/', subject: $file) : '';
    $relative = str_starts_with($path, $prefix) ? substr($path, offset: strlen($prefix)) : '';
    if (!is_int($line) || $line < 1 || $line > 100_000 || !in_array($relative, $publicSources, strict: true)
        || preg_match('~\A(?:app|tools|tests/(?:Support|Server|ServerCross))/[A-Za-z0-9_-]{1,128}\.php\z~D', $relative) !== 1) {
        return ['source' => null, 'line' => null];
    }
    return ['source' => $relative, 'line' => $line];
}

/**
 * Project-native evidence procedures, explicitly required by verify-server.php.
 * No procedure launches an engine, rewrites a declaration, or captures a fingerprint.
 *
 * @return array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}>
 */
function server_native_cases(array $manifest, string $lane): array
{
    $lanes = JsonRecord::checked($manifest['cases'] ?? null);
    $input = JsonRecord::checked($lanes[$lane] ?? null);
    if (!array_is_list($input) || $input === []) { throw new RuntimeException('Required native identity declaration missing.'); }
    $required = [];
    foreach (array_keys($input) as $index) {
        $record = server_native_case_declaration(JsonRecord::checked($input[$index] ?? null), $lane);
        if (array_key_exists($record['identity'], $required)) { throw new RuntimeException('Duplicate declared native identity.'); }
        $required[$record['identity']] = $record;
    }
    return $required;
}

/** @return array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int} */
function server_native_case_declaration(array $record, string $lane = 'main'): array
{
    $identity = SqlColumn::string($record, 'identity'); $path = SqlColumn::string($record, 'path');
    $baseline = $lane === 'baseline'; $original = preg_match('~\Atests/(?:Unit|Network|Http)/~D', $path) === 1;
    if (!in_array($lane, ['main', 'cross', 'baseline'], strict: true) || $baseline !== $original
        || !array_key_exists('deadlineSeconds', $record) || ($baseline && $record['deadlineSeconds'] !== null)) {
        throw new RuntimeException('Native budget policy refused.');
    }
    $assertions = SqlColumn::integer($record, 'minimumAssertions'); $deadline = $baseline ? null : SqlColumn::integer($record, 'deadlineSeconds');
    if ($assertions < 1 || (!$baseline && $deadline !== server_native_deadline($identity, $path)) || ($record['dataRows'] ?? null) !== []) {
        throw new RuntimeException('Unreviewed native assertion, deadline or data-row declaration.');
    }
    if (!str_starts_with($path, 'tests/') || str_contains($path, '..') || !str_ends_with($path, 'Test.php')) {
        throw new RuntimeException('Native source selector refused.');
    }
    return ['identity' => $identity, 'path' => $path, 'minimumAssertions' => $assertions, 'deadlineSeconds' => $deadline];
}

function server_native_deadline(string $identity, string $path): int
{
    if (str_starts_with($path, 'tests/Http/') || str_starts_with($path, 'tests/Network/')) { return 20; }
    $http = ['Tablo\\Tests\\Server\\EngineFlowTest::selectedEngineServesSetupCsrfCookieLoginAndLogout',
        'Tablo\\Tests\\Server\\EngineFlowTest::selectedEngineCommitsSiteTokenCrudAndSettingsWithRealForeignKeys',
        'Tablo\\Tests\\Server\\EngineFlowTest::selectedEngineManualCliAndWorkerChecksCommitTheSameHistoryPipeline',
        'Tablo\\Tests\\Server\\EngineFlowTest::selectedEngineProtectedAdminCliInvalidatesThePreviousWebCredential',
        'Tablo\\Tests\\Server\\EngineReadApiTest::bearerHttpUsesSelectedReaderWithoutKeyCookieOrPrivateColumns',
        'Tablo\\Tests\\Server\\EngineReadApiTest::nonReadyCatalogCannotServeBearerDataAndEarlyUnauthorizedStaysFixed'];
    return in_array($identity, $http, strict: true) ? 20 : 8;
}

/**
 * @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $required
 * @return array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string}
 */
function server_native_junit(string $root, string $report, array $required): array
{
    $document = server_native_document($report);
    $seen = []; $assertions = 0;
    foreach ($document->getElementsByTagName('testcase') as $case) {
        $identity = server_native_identity($case, $required);
        if (array_key_exists($identity, $seen)) { throw new RuntimeException('Duplicate actual native identity.'); }
        $record = server_native_case_result($root, $case, $required[$identity] ?? throw new RuntimeException('Native identity missing.'));
        $seen[$identity] = $record; $assertions += $record['assertions'];
    }
    if ($seen === [] || count($seen) !== count($required) || array_diff_key($required, $seen) !== []) {
        throw new RuntimeException('Required native identity union missing.');
    }
    return ['cases' => $seen, 'assertions' => $assertions, 'platform' => PHP_OS_FAMILY];
}

function server_native_document(string $report): DOMDocument
{
    if (is_link($report)) { throw new RuntimeException('Linked native evidence refused.'); }
    $bytes = \Tablo\RequiredFile::bytes(file_get_contents($report, length: 8_388_609));
    if (strlen($bytes) > 8_388_608 || stripos($bytes, needle: '<!DOCTYPE') !== false) { throw new RuntimeException('Native XML envelope refused.'); }
    $document = new DOMDocument();
    if (!$document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS)) { throw new RuntimeException('Native XML parsing failed.'); }
    return $document;
}

/** @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $required */
function server_native_identity(DOMElement $case, array $required): string
{
    $class = $case->getAttribute('classname'); $name = $case->getAttribute('name');
    $method = $class . '::' . $name;
    if (array_key_exists($method, $required)) { return $method; }
    $position = strrpos($class, needle: '\\');
    $short = $position === false ? $class : substr($class, offset: $position + 1);
    if (!array_key_exists($class, $required) || !in_array($name, [$class, $short], strict: true)) {
        throw new RuntimeException('Unreviewed actual native identity.');
    }
    return $class;
}

/**
 * @param array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int} $spec
 * @return array{status: string, assertions: int, seconds: float}
 */
function server_native_case_result(string $root, DOMElement $case, array $spec): array
{
    $ns = 'https://php-testo.github.io/schema/junit/1';
    if ([$case->getAttributeNS($ns, 'data-set'), $case->getAttributeNS($ns, 'data-provider')] !== ['', '']) { throw new RuntimeException('Native data-row qualification refused.'); }
    $expected = realpath($root . '/' . $spec['path']);
    if ($expected === false || str_replace('\\', replace: '/', subject: $case->getAttribute('file')) !== str_replace('\\', replace: '/', subject: $expected)) {
        throw new RuntimeException('Native source identity refused.');
    }
    if ($case->getAttributeNS($ns, 'status') === 'skipped') { return server_native_original_platform_skip($case, $spec); }
    if ($case->getAttributeNS($ns, 'status') !== 'passed') { throw new RuntimeException('Native status qualification refused.'); }
    server_native_passed_only($case);
    $assertions = DatabaseInteger::positive($case->getAttribute('assertions'));
    if ($assertions < $spec['minimumAssertions']) { throw new RuntimeException('Native behavioral assertions missing.'); }
    if (($spec['deadlineSeconds'] === null) !== (preg_match('~\Atests/(?:Unit|Network|Http)/~D', $spec['path']) === 1)) {
        throw new RuntimeException('Native method budget policy refused.');
    }
    return ['status' => 'passed', 'assertions' => $assertions, 'seconds' => server_native_elapsed($case, $spec['deadlineSeconds'])];
}

/** The sole original platform exception is checked against the actual PHP runtime, never an input alias.
 * @param array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int} $spec
 * @return array{status: string, assertions: int, seconds: float} */
function server_native_original_platform_skip(DOMElement $case, array $spec): array
{
    $policy = server_native_baseline_platform_policy();
    $skips = $case->getElementsByTagName('skipped'); $skip = $skips->item(0);
    if (PHP_OS_FAMILY !== 'Linux' || [$spec['identity'], $spec['path'], $spec['deadlineSeconds']]
        !== [$policy['identity'], $policy['path'], null] || $case->getAttribute('assertions') !== '0'
        || $skips->length !== 1 || !$skip instanceof DOMElement || $skip->parentNode !== $case
        || $skip->getAttribute('message') !== $policy['reason'] || $skip->textContent !== '') {
        throw new RuntimeException('Original native platform skip qualification refused.');
    }
    foreach (['failure', 'error', 'flakyFailure', 'flakyError', 'rerunFailure', 'rerunError'] as $tag) {
        if ($case->getElementsByTagName($tag)->length !== 0) { throw new RuntimeException('Non-passed native outcome accompanies platform skip.'); }
    }
    return ['status' => 'skipped_platform', 'assertions' => 0, 'seconds' => server_native_elapsed($case, maximum: null)];
}

/** @return array{platform: string, identity: string, path: string, reason: string, windowsPassed: int, linuxPassed: int} */
function server_native_baseline_platform_policy(): array
{
    return ['platform' => 'Linux', 'identity' => 'Tablo\\Tests\\Network\\PasswordHelperTest::shippedHelperPreservesHostEncodingAndExactCredentials',
        'path' => 'tests/Network/PasswordHelperTest.php', 'reason' => 'Native Windows PowerShell host required.', 'windowsPassed' => 441, 'linuxPassed' => 440];
}

/** @param array{cases: array<string, array{status: string, assertions: int, seconds: float}>, assertions: int, platform: string} $native */
function server_native_baseline_summary(array $summary, array $native): void
{
    if ($native['platform'] !== PHP_OS_FAMILY || count($native['cases']) !== 441) { throw new RuntimeException('Original native platform or baseline union refused.'); }
    $counts = server_native_baseline_counts($native['cases']);
    if ($counts['assertions'] !== $native['assertions']) { throw new RuntimeException('Original native assertion sum disagrees.'); }
    server_native_baseline_totals($summary, $counts);
}

/** @param array<string, array{status: string, assertions: int, seconds: float}> $cases
 * @return array{assertions: int, skipped: int} */
function server_native_baseline_counts(array $cases): array
{
    $skipped = 0; $assertions = 0;
    foreach ($cases as $identity => $case) {
        if ($case['status'] === 'passed' && $case['assertions'] > 0) { $assertions += $case['assertions']; continue; }
        if ([PHP_OS_FAMILY, $identity, $case['status'], $case['assertions']] !== ['Linux', server_native_baseline_platform_policy()['identity'], 'skipped_platform', 0]) {
            throw new RuntimeException('Original native baseline outcome refused.');
        }
        $skipped++;
    }
    return ['assertions' => $assertions, 'skipped' => $skipped];
}

/** @param array{assertions: int, skipped: int} $counts */
function server_native_baseline_totals(array $summary, array $counts): void
{
    $expectedSkips = PHP_OS_FAMILY === 'Linux' ? 1 : 0;
    $assertions = $counts['assertions'];
    $totals = JsonRecord::checked($summary['totals'] ?? null);
    if ($counts['skipped'] !== $expectedSkips || $assertions < 1 || ($summary['status'] ?? null) !== 'passed'
        || [SqlColumn::integer($totals, 'total'), SqlColumn::integer($totals, 'passed'), SqlColumn::integer($totals, 'assertions'), $totals['skipped'] ?? 0]
            !== [441, 441 - $expectedSkips, $assertions, $expectedSkips] || ($summary['failures'] ?? null) !== []) {
        throw new RuntimeException('Original native baseline summary and JUnit disagree.');
    }
    foreach (array_keys($totals) as $name) {
        if (!in_array($name, ['total', 'passed', 'assertions', 'skipped'], strict: true) && SqlColumn::integer($totals, (string) $name) !== 0) { throw new RuntimeException('Unreviewed baseline summary status.'); }
    }
}

function server_native_passed_only(DOMElement $case): void
{
    foreach (['failure', 'error', 'skipped', 'flakyFailure', 'flakyError', 'rerunFailure', 'rerunError'] as $tag) {
        if ($case->getElementsByTagName($tag)->length !== 0) { throw new RuntimeException('Non-passed native case refused.'); }
    }
}

function server_native_elapsed(DOMElement $case, ?int $maximum): float
{
    $value = $case->getAttribute('time');
    if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $value) !== 1 || !is_numeric($value)) {
        throw new RuntimeException('Native case time refused.');
    }
    $seconds = (float) $value;
    if (!is_finite($seconds) || ($maximum !== null && $seconds > $maximum)) {
        throw new RuntimeException('Native case deadline exceeded; split cases instead of widening.');
    }
    return $seconds;
}

function server_native_summary(array $summary, int $required, int $assertions): void
{
    $totals = JsonRecord::checked($summary['totals'] ?? null);
    if (($summary['status'] ?? null) !== 'passed' || $assertions < 1
        || [SqlColumn::integer($totals, 'total'), SqlColumn::integer($totals, 'passed'), SqlColumn::integer($totals, 'assertions')]
            !== [$required, $required, $assertions]) { throw new RuntimeException('Native summary and JUnit do not agree.'); }
}

/** The selectors remain the default verify selectors; only private evidence switches are optional.
 * @return list<string> */
function server_verify_native_command(?string $report = null): array
{
    $command = [PHP_BINARY, 'vendor/bin/testo', 'run', '--path', 'tests/Unit', '--path', 'tests/Network', '--path', 'tests/Http'];
    return $report === null ? $command : [...$command, '--json', '--log-junit=' . $report, '--no-ansi'];
}

/** Check structure before touching a caller-supplied path. This never adopts an existing output directory.
 * @param array<string, string> $environment */
function server_verify_native_directory(string $root, string $directory, array $environment): string
{
    $normalize = static fn (string $value): string => str_replace('\\', replace: '/', subject: $value);
    $root = $normalize($root); $directory = $normalize($directory); $runtime = dirname($root);
    $nonce = $environment['TABLO_TEST_RUN_NONCE'] ?? ''; $parent = dirname($directory);
    if (preg_match('/\A[0-9a-f]{32}\z/D', $nonce) !== 1 || basename($runtime) !== 'tablo-server-' . $nonce
        || basename($root) !== 'source' || $root !== $normalize($environment['TABLO_TEST_SOURCE_ROOT'] ?? '')
        || $normalize(sys_get_temp_dir()) !== $runtime . '/tmp' || dirname($parent) !== $runtime . '/tmp'
        || preg_match('/\Atablo-server-verify-[0-9a-f]{16}\z/D', basename($parent)) !== 1
        || $directory !== $parent . '/composer-verify') {
        throw new \RuntimeException('Explicit private native evidence ownership refused.');
    }
    server_verify_native_ancestors([$runtime, $root, $runtime . '/tmp', $parent]);
    if (file_exists($directory) || is_link($directory)) { throw new \RuntimeException('Native evidence output must be fresh.'); }
    return $directory;
}

/** @param list<string> $paths */
function server_verify_native_ancestors(array $paths): void
{
    foreach ($paths as $path) {
        if (is_link($path) || !is_dir($path) || str_replace('\\', replace: '/', subject: (string) realpath($path)) !== $path) {
            throw new \RuntimeException('Regular owned native evidence ancestor required.');
        }
    }
}

/** Reserve only an explicit positively owned fresh private directory after the source guard has passed. */
function server_verify_native_output(string $root): ?string
{
    $selected = getenv('TABLO_VERIFY_NATIVE_DIRECTORY');
    if ($selected === false) { return null; }
    $environment = [];
    foreach (['TABLO_TEST_RUN_NONCE', 'TABLO_TEST_SOURCE_ROOT'] as $name) {
        $value = getenv($name); if (is_string($value)) { $environment[$name] = $value; }
    }
    $directory = server_verify_native_directory($root, $selected, $environment);
    server_verify_native_provenance($root);
    if (!mkdir($directory, permissions: 0o700) || !putenv('TABLO_VERIFY_CAPTURE_DIRECTORY=' . $directory)) {
        throw new \RuntimeException('Cannot reserve private native evidence.');
    }
    return $directory . '/baseline.xml';
}

/** @return array{root: string, files: array<string, string>, plan: string, planSha256: string} */
function server_verify_native_provenance(string $root): array
{
    $root = str_replace('\\', replace: '/', subject: $root);
    $plan = getenv('TABLO_TEST_CELL_PLAN'); $expected = getenv('TABLO_TEST_CELL_PLAN_SHA256');
    if (!is_string($plan) || str_replace('\\', replace: '/', subject: $plan) !== dirname($root) . '/evidence/started-cell.json'
        || !is_string($expected) || preg_match('/\A[0-9a-f]{64}\z/D', $expected) !== 1
        || is_link($plan) || !hash_equals($expected, \Tablo\RequiredFile::digest($plan))) {
        throw new \RuntimeException('Exact native cell plan provenance refused.');
    }
    $files = [];
    foreach (['composer.lock', 'lekalo.lock', 'contracts/php-bindings.json', 'contracts/reviewed-dependencies.json', 'tools/server-native-tests.json'] as $path) {
        $files[$path] = \Tablo\RequiredFile::digest($root . '/' . $path);
    }
    return ['root' => $root, 'files' => $files, 'plan' => $plan, 'planSha256' => $expected];
}

/** The manifest refers to the actual existing run_gate child, never a reconstructed native result. */
function server_verify_native_export(string $root, string $report, ?string $prefix): void
{
    if ($prefix === null) { throw new \RuntimeException('Actual native gate capture missing.'); }
    $directory = dirname($report);
    $record = ['schema' => 'tablo/verify-native-export/v1', 'provenance' => server_verify_native_provenance($root),
        'report' => $report, 'reportSha256' => \Tablo\RequiredFile::digest($report), 'prefix' => $prefix];
    $output = fopen($directory . '/native.json', mode: 'xb');
    if ($output === false) { throw new \RuntimeException('Cannot reserve native export manifest.'); }
    try {
        $bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (fwrite($output, $bytes) !== strlen($bytes) || !fflush($output) || !fsync($output)) { throw new \RuntimeException('Native export manifest write failed.'); }
    } finally { fclose($output); }
}

/** @param list<string> $command
 * @return array{stdout: string, files: array<string, string>, pid: int} */
function server_verify_native_gate(string $root, string $directory, string $prefix, array $command): array
{
    if (dirname($prefix) !== $directory || preg_match('/\Agate-[0-9a-f]{16}\z/D', basename($prefix)) !== 1) {
        throw new \RuntimeException('Actual native capture path refused.');
    }
    $records = [];
    foreach (['started', 'closed'] as $phase) {
        $path = $prefix . '-' . $phase . '.json';
        if (is_link($path) || !is_file($path)) { throw new \RuntimeException('Regular native child receipt required.'); }
        $records[$phase] = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)));
    }
    $closed = $records['closed'];
    $pid = server_verify_native_child($records['started'], $closed, $command, $root);
    $files = ['started' => $prefix . '-started.json', 'closed' => $prefix . '-closed.json'];
    foreach (['stdout', 'stderr'] as $stream) {
        $path = $prefix . '.' . $stream;
        if (is_link($path) || !is_file($path) || !hash_equals(\Tablo\SqlColumn::string($closed, $stream . '_sha256'), \Tablo\RequiredFile::digest($path))) {
            throw new \RuntimeException('Actual native child raw stream differs.');
        }
        $files[$stream] = $path;
    }
    return ['stdout' => \Tablo\RequiredFile::bytes(file_get_contents($files['stdout'])), 'files' => $files, 'pid' => $pid];
}

/** @param list<string> $command */
function server_verify_native_child(array $started, array $closed, array $command, string $root): int
{
    $pid = \Tablo\SqlColumn::integer($closed, 'pid');
    $normalize = static fn (string $value): string => str_replace('\\', replace: '/', subject: $value);
    if ($pid <= 0 || $pid !== ($started['pid'] ?? null) || [$started['argv'] ?? null, $closed['argv'] ?? null] !== [$command, $command]
        || [$normalize(\Tablo\SqlColumn::string($started, 'cwd')), $normalize(\Tablo\SqlColumn::string($closed, 'cwd'))] !== [$normalize($root), $normalize($root)]
        || [$closed['proc_close'] ?? null, $closed['exit'] ?? null] !== [0, 0]) {
        throw new \RuntimeException('Same native child identity or actual closure refused.');
    }
    return $pid;
}

/** Read only the same child export, with current source, plan, report and raw stream digests. */
function server_verify_native_import(string $root, string $directory): array
{
    $path = $directory . '/native.json';
    if (is_link($directory) || is_link($path) || !is_file($path)) { throw new \RuntimeException('Private native export missing.'); }
    $record = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)));
    $report = $directory . '/baseline.xml';
    if ([$record['schema'] ?? null, $record['provenance'] ?? null, $record['report'] ?? null]
        !== ['tablo/verify-native-export/v1', server_verify_native_provenance($root), $report]
        || is_link($report) || !hash_equals(\Tablo\SqlColumn::string($record, 'reportSha256'), \Tablo\RequiredFile::digest($report))) {
        throw new \RuntimeException('Actual native source, plan or JUnit differs.');
    }
    $gate = server_verify_native_gate($root, $directory, \Tablo\SqlColumn::string($record, 'prefix'), server_verify_native_command($report));
    return $gate + ['report' => $report, 'provenance' => $record['provenance'] ?? null, 'manifest' => $path];
}
