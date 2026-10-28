<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Testo\Assert;
use Testo\Test;

final class EngineFixtureSafetyTest
{
    #[Test]
    public function ownedResourceAbsenceRequiresExactKindNameAndObservedNotFoundExit(): void
    { engine_fixture_owned_resource_absence(); }

    #[Test]
    public function mainOnlyAndDeclaredCrossScopesRestoreAllVariablesAndRefuseMissingAuthority(): void
    { engine_fixture_main_and_cross_scope(); engine_fixture_loopback_publication(); engine_fixture_context_admission(); }

    #[Test]
    public function cleanupIdentityChecksShareTheExpiredDeadlineBeforeAnyCommand(): void
    { engine_fixture_cleanup_late_identity(); engine_fixture_observer_admission(); engine_fixture_composer_phase_closure(); }

    #[Test]
    public function completeDirectChildReceiptUnionBlocksMissingClosureBeforeCatalogReuse(): void
    { engine_fixture_complete_child_union(); }

    #[Test]
    public function explicitCloseRetainsBothStreamsAfterFixtureRemovalWithoutFinish(): void
    { engine_fixture_explicit_close_streams(); }

    #[Test]
    public function controllerTemporaryFilesStayInsideItsFreshPrivateRootAndRejectOtherParents(): void
    { engine_fixture_private_controller_parent(); engine_fixture_materialized_source_children(); engine_fixture_vendor_autoload_custody(); }

    #[Test]
    public function failedCatalogBootstrapKeepsUnknownIntentsAndCannotReplayOrResetThem(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixtureCatalog.php';
        $nonce = 'abcdef0123456789abcdef0123456789'; $attempts = 0; $primary = new \RuntimeException('synthetic administrative outcome unknown');
        $catalogs = new \Tablo\Tools\ServerFixtureCatalog($nonce, 'mysql', static function (#[\SensitiveParameter] string $sql) use (&$attempts, $primary): never { $attempts++; throw $primary; });
        $passwords = ['writer' => str_repeat('a', times: 64), 'reader' => str_repeat('b', times: 64), 'migrator' => str_repeat('c', times: 64),
            'deniedDdl' => str_repeat('d', times: 64), 'metadata' => str_repeat('e', times: 64)];
        $caught = null;
        try { $catalogs->bootstrap($passwords); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught, $primary); Assert::same($attempts, 1);
        Assert::same(count($catalogs->snapshot()), 8);
        Assert::same(array_unique(array_column($catalogs->snapshot(), 'phase')), ['bootstrap-intent']);
        $caught = null;
        try { $catalogs->bootstrap($passwords); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Fixture account/catalog bootstrap cannot be replayed.'); Assert::same($attempts, 1);
        $fixtures = \Tablo\Tools\ServerFixturePlan::fixtures(['fixtures' => [['alias' => 'migration-fresh', 'engine' => 'selected', 'initial' => 'empty']]], $nonce, 'mysql');
        $caught = null;
        try { $catalogs->begin('Synthetic\\Fixture::refused', $fixtures); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class); Assert::same($attempts, 1);
        Assert::same(array_unique(array_column($catalogs->snapshot(), 'phase')), ['bootstrap-intent']);
    }

    #[Test]
    public function finiteCatalogSlotsAndFixtureAliasesRefuseForeignOrUnboundedPlans(): void
    {
        engine_fixture_native_binding_set();
        require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
        $nonce = '1234567890abcdef1234567890abcdef';
        foreach (['mysql' => 'm', 'mariadb' => 'a'] as $engine => $letter) {
            $slots = \Tablo\Tools\ServerFixturePlan::slots($nonce, $engine);
            Assert::same(count($slots), 8); Assert::same(count(array_unique($slots)), 8);
            Assert::same([$slots[0] ?? null, $slots[7] ?? null], ['ti39_1234567890abcdef_' . $letter . '_000', 'ti39_1234567890abcdef_' . $letter . '_007']);
            Assert::same(\Tablo\Tools\ServerFixturePlan::accounts($nonce, $engine), ['writer' => 'i391234567890abw', 'reader' => 'i391234567890abr',
                'migrator' => 'i391234567890abm', 'deniedDdl' => 'i391234567890abd']);
        }
        $one = ['alias' => 'migration-fresh', 'engine' => 'selected', 'initial' => 'empty'];
        $valid = \Tablo\Tools\ServerFixturePlan::fixtures(['fixtures' => [$one]], $nonce, 'mysql');
        Assert::same($valid, [['alias' => 'migration-fresh', 'engine' => 'mysql', 'initial' => 'empty', 'catalog' => 'ti39_1234567890abcdef_m_000']]);
        $invalid = [[$one, $one], [array_replace($one, ['alias' => '../foreign'])], [array_replace($one, ['engine' => 'postgres'])],
            [array_replace($one, ['engine' => 'mariadb'])], [array_replace($one, ['initial' => 'resumed'])], [$one + ['sql' => 'arbitrary']]];
        $tooMany = [];
        for ($index = 0; $index < 9; $index++) { $tooMany[] = array_replace($one, ['alias' => 'case-' . $index]); }
        $invalid[] = $tooMany;
        foreach ($invalid as $fixtures) {
            $caught = null;
            try { \Tablo\Tools\ServerFixturePlan::fixtures(['fixtures' => $fixtures], $nonce, 'mysql'); } catch (\RuntimeException $error) { $caught = $error; }
            Assert::instanceOf($caught, \RuntimeException::class);
        }
        $cross = \Tablo\Tools\ServerFixturePlan::fixtures(['fixtures' => [$one, array_replace($one, ['engine' => 'mariadb'])]], $nonce, 'mysql', lane: 'cross');
        Assert::same(array_column($cross, 'engine'), ['mysql', 'mariadb']);
        Assert::same(array_column($cross, 'catalog'), ['ti39_1234567890abcdef_m_000', 'ti39_1234567890abcdef_a_000']);
    }

    #[Test]
    public function configurationPasswordCannotAppearInARealFailureTraceWithArgumentsEnabled(): void
    {
        $password = \Tablo\Tests\Support\FixtureCredentials::value(label: 'configuration-trace-canary');
        $configuration = \Tablo\DatabaseConfiguration::fromEnvironment(environment: ['TABLO_DB_DRIVER' => 'mysql', 'TABLO_DB_HOST' => '127.0.0.1',
            'TABLO_DB_PORT' => '3306', 'TABLO_DB_NAME' => 'synthetic', 'TABLO_DB_TLS' => 'local', 'TABLO_DB_USER' => 'writer', 'TABLO_DB_PASSWORD' => $password]);
        $before = ini_get('zend.exception_ignore_args'); \Tablo\RuntimeIni::set('zend.exception_ignore_args', '0');
        try {
            $caught = null;
            try { \Tablo\DatabaseConnectionFactory::open($configuration, mode: 'unrecognized'); } catch (\Tablo\DatabaseFailure $error) { $caught = $error; }
            Assert::same($caught?->category, 'configuration'); Assert::same($caught?->getPrevious(), null);
            $trace = \Tablo\JsonEncoding::encode($caught?->getTrace());
            Assert::false(str_contains($trace, $password));
            Assert::true(str_contains($trace, 'DatabaseConnectionFactory'));
            engine_fixture_safe_native_failure_codes();
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', is_string($before) ? $before : '1'); }
    }

    #[Test]
    public function foreignInheritedKeyIsRefusedBeforeVaultConstructionOrAnyConnection(): void
    {
        $before = getenv('TABLO_TOKEN_KEY_FILE');
        $canary = EngineSchedule::directory() . '/cases/constraints/never-created-key-canary.bin';
        Assert::false(file_exists($canary)); putenv('TABLO_TOKEN_KEY_FILE=' . $canary);
        try {
            $caught = null;
            try { new EngineFixture('constraints'); } catch (\RuntimeException $error) { $caught = $error; }
            Assert::same($caught?->getMessage(), 'Fixture key environment refused.');
            Assert::same($caught?->getPrevious(), null); Assert::same(getenv('TABLO_TOKEN_KEY_FILE'), $canary);
            Assert::false(file_exists($canary));
        } finally { putenv(is_string($before) ? 'TABLO_TOKEN_KEY_FILE=' . $before : 'TABLO_TOKEN_KEY_FILE'); }
        Assert::same(getenv('TABLO_TOKEN_KEY_FILE'), $before);
    }
}

/** Uses a real independently observed process; the incomplete union is a separate authored evidence fixture. */
function engine_fixture_complete_child_union(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixtureCatalog.php';
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-child-union-');
    $before = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY');
    $sink = $directory->path . '/complete'; $partial = $directory->path . '/partial';
    Assert::true(mkdir($sink, permissions: 0o700)); Assert::true(mkdir($partial, permissions: 0o700));
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $sink);
    try {
        $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, '-r', 'exit(0);'], $directory, []);
        Assert::same([$child['exit_code'], $child['stdout'], $child['stderr']], [0, '', '']);
        $records = \Tablo\Tools\server_fixture_catalog_closure_records($sink);
        Assert::same([count($records['children']), count($records['childCloses'])], [1, 1]);
        $start = $records['children'][0] ?? throw new \RuntimeException('Real child start evidence missing.');
        $closed = $records['childCloses'][0] ?? throw new \RuntimeException('Real child close evidence missing.');
        Assert::true(\Tablo\SqlColumn::integer($start, 'pid') > 0);
        Assert::same([$start['pid'] ?? null, $start['sourceReceipt'] ?? null], [$closed['pid'] ?? null, $closed['sourceReceipt'] ?? null]);
        $copy = \Tablo\SqlColumn::string($closed, 'receiptCopy'); $digest = \Tablo\RequiredFile::digest($copy);
        $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($copy);
        Assert::same([$receipt['closed'], $receipt['root_exit_observed'], $receipt['exit_code']], [true, true, 0]);
        \Tablo\Tools\server_fixture_catalog_descendants_closed($sink);
        Assert::same($digest, \Tablo\SqlColumn::string($closed, 'receiptSha256'));
        $bytes = \Tablo\JsonEncoding::encode($start);
        Assert::same(file_put_contents($partial . '/direct-child-start-synthetic.json', $bytes), strlen($bytes));
        $caught = null;
        try { \Tablo\Tools\server_fixture_catalog_descendants_closed($partial); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Native nested direct-child closure union is incomplete.');
        Assert::same(\Tablo\RequiredFile::digest($copy), $digest);
    } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
    finally { putenv($before === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $before); $directory->close(); }
}

function engine_fixture_explicit_close_streams(): void
{
    foreach (['normal', 'forced', 'timeout'] as $mode) { engine_fixture_explicit_close_stream($mode); }
}

/** @param 'normal'|'forced'|'timeout' $mode */
function engine_fixture_explicit_close_stream(string $mode): void
{
    $owner = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-close-evidence-');
    $childDirectory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-closed-child-');
    $before = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY'); $sink = engine_fixture_explicit_close_sink($owner); $child = null;
    try {
        $child = engine_fixture_explicit_close_child($mode, $childDirectory);
        engine_fixture_explicit_close_protocol($mode, $child, $childDirectory);
        $child->close(); $child = null;
        engine_fixture_explicit_close_retention($mode, $sink, $childDirectory);
    } catch (\Throwable $error) { $owner->retainFailure($error); $childDirectory->retainFailure($error); throw $error; }
    finally { $child?->close(); putenv($before === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $before); $childDirectory->close(); $owner->close(); }
}

function engine_fixture_explicit_close_sink(\Tablo\Tests\Support\TemporaryDirectory $owner): string
{
    $sink = \Tablo\Tests\Support\SessionEvidence::directory();
    if ($sink !== null) { return $sink; }
    $sink = $owner->path . '/saved'; Assert::true(mkdir($sink, permissions: 0o700));
    putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $sink);
    return $sink;
}

/** @param 'normal'|'forced'|'timeout' $mode */
function engine_fixture_explicit_close_child(string $mode, \Tablo\Tests\Support\TemporaryDirectory $directory): \Tablo\Tests\Support\Subprocess
{
    $body = 'fwrite(STDERR,"closed-err");fwrite(STDOUT,"closed-out");fflush(STDERR);fflush(STDOUT);';
    $body .= match ($mode) {
        'normal' => 'while(fgets(STDIN)!==false){}',
        'forced' => 'while(true){usleep(20000);}',
        'timeout' => 'fgets(STDIN);fwrite(STDOUT,"owned-ack");fflush(STDOUT);while(true){usleep(20000);}',
    };
    return new \Tablo\Tests\Support\Subprocess([PHP_BINARY, '-r', $body], $directory);
}

/** @param 'normal'|'forced'|'timeout' $mode */
function engine_fixture_explicit_close_protocol(string $mode, \Tablo\Tests\Support\Subprocess $child, \Tablo\Tests\Support\TemporaryDirectory $directory): void
{
    $child->awaitOutput('closed-out');
    if ($mode === 'timeout') { engine_fixture_await_exit_refusals($child, $directory); return; }
    if ($mode === 'normal') { $child->send(''); $child->awaitExit(); }
}

function engine_fixture_explicit_close_record(string $sink, string $directory): array
{
    $matches = []; $paths = glob($sink . '/direct-child-close-*.json');
    if ($paths === false) { throw new \RuntimeException('Closed stream evidence discovery failed.'); }
    foreach ($paths as $path) {
        $record = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($path)));
        if (str_starts_with(\Tablo\SqlColumn::string($record, 'sourceReceipt'), $directory . '/')) { $matches[] = $record; }
    }
    Assert::same(count($matches), 1);
    return $matches[0] ?? throw new \RuntimeException('Real explicit-close evidence absent.');
}

/** @param 'normal'|'forced'|'timeout' $mode */
function engine_fixture_explicit_close_retention(string $mode, string $sink, \Tablo\Tests\Support\TemporaryDirectory $directory): void
{
    $closed = engine_fixture_explicit_close_record($sink, $directory->path);
    $receipt = \Tablo\Tests\Support\SubprocessReceipt::read(\Tablo\SqlColumn::string($closed, 'receiptCopy'));
    Assert::same([$receipt['closed'], $receipt['root_exit_observed']], [true, true]);
    Assert::true(\Tablo\SqlColumn::integer($closed, 'pid') > 0);
    Assert::same($receipt['pid'], \Tablo\SqlColumn::integer($closed, 'pid'));
    engine_fixture_explicit_close_outcome($receipt, $mode);
    if ($mode === 'timeout') { engine_fixture_await_exit_failure_receipt($receipt, $directory); return; }
    $directory->close(); Assert::false(is_dir($directory->path));
    engine_fixture_explicit_close_copies($receipt, $closed);
}

function engine_fixture_explicit_close_copies(array $record, array $closed): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    $streams = \Tablo\JsonRecord::checked($closed['streams'] ?? null);
    foreach (['stdout' => 'closed-out', 'stderr' => 'closed-err'] as $name => $expected) {
        $stream = \Tablo\JsonRecord::checked($streams[$name] ?? null); $copy = \Tablo\SqlColumn::string($stream, 'path');
        $original = $receipt['streams'][$name] ?? throw new \RuntimeException('Real closed stream receipt absent.');
        Assert::false(is_file($original['path'])); Assert::same(file_get_contents($copy), $expected);
        Assert::same(\Tablo\RequiredFile::digest($copy), $original['sha256']);
        Assert::same(\Tablo\SqlColumn::string($stream, 'sha256'), $original['sha256']);
    }
}

/** @param 'normal'|'forced'|'timeout' $mode */
function engine_fixture_explicit_close_outcome(array $record, string $mode): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    Assert::same($receipt['platform'] ?? null, PHP_OS_FAMILY);
    $stages = array_column($receipt['events'], 'stage'); Assert::false(in_array('finish', $stages, strict: true));
    $exits = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'root-exit'));
    $closes = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'proc-close'));
    Assert::same([count($exits), count($closes)], [1, 1]);
    $exit = $exits[0] ?? throw new \RuntimeException('Real child stopped observation absent.');
    $close = $closes[0] ?? throw new \RuntimeException('Real child close observation absent.');
    engine_fixture_explicit_close_status($receipt, $exit, $close);
    if ($mode === 'normal') { engine_fixture_normal_close_outcome($receipt, $exit, $stages); return; }
    engine_fixture_forced_close_outcome($receipt, $exit);
}

function engine_fixture_explicit_close_status(array $record, array $exit, array $close): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    Assert::true(is_int($receipt['proc_close'] ?? null)); Assert::same($receipt['proc_close'] ?? null, $close['native_exit_code'] ?? null);
    Assert::same([$receipt['exit_code'], $receipt['signaled'] ?? null], [$exit['exit_code'] ?? null, $exit['signaled'] ?? null]);
}

function engine_fixture_normal_close_outcome(array $record, array $exit, array $stages): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    Assert::true(is_int($receipt['exit_code']));
    Assert::same([$receipt['exit_code'], $receipt['signaled'] ?? null, $receipt['term_signal'] ?? null], [0, false, null]);
    Assert::same($exit['native_exit_code'] ?? null, 0);
    Assert::true(in_array('input-closed', $stages, strict: true)); Assert::true(in_array('exit-selection', $stages, strict: true));
    Assert::false(in_array('terminate-direct-child', $stages, strict: true));
    Assert::false(in_array('termination-request-result', $stages, strict: true));
}

function engine_fixture_forced_close_outcome(array $record, array $exit): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    $requests = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'termination-request-result'));
    Assert::same(count($requests), 1); $request = $requests[0] ?? throw new \RuntimeException('Real child termination request absent.');
    Assert::same([$request['signal'] ?? null, $request['accepted'] ?? null], [15, true]);
    if (PHP_OS_FAMILY === 'Windows') {
        engine_fixture_windows_close_outcome($receipt, $exit);
        return;
    }
    Assert::same([$receipt['exit_code'], $receipt['signaled'] ?? null, $receipt['term_signal'] ?? null], [null, true, 15]);
    Assert::same($exit['signal'] ?? null, 15);
}

function engine_fixture_windows_close_outcome(array $record, array $exit): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    Assert::true(is_int($receipt['exit_code'])); Assert::same($receipt['exit_code'], $exit['native_exit_code'] ?? null);
    Assert::same([$receipt['signaled'] ?? null, $receipt['term_signal'] ?? null], [false, null]);
}

function engine_fixture_await_exit_refusals(\Tablo\Tests\Support\Subprocess $child, \Tablo\Tests\Support\TemporaryDirectory $directory): void
{
    foreach ([NAN, INF, -INF, -1.0] as $timeout) {
        $caught = null;
        try { $child->awaitExit($timeout); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Invalid test command exit observation timeout');
    }
    Assert::same(glob($directory->path . '/command-*.json'), []); Assert::false($directory->hasFailure());
    $child->sendBarrier("still-owned\n"); $child->awaitOutput('owned-ack');
    $caught = null;
    try { $child->awaitExit(0); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::true(str_contains($caught?->getMessage() ?? '', 'stage=exit-selection'));
    Assert::true($directory->hasFailure());
}

function engine_fixture_await_exit_failure_receipt(array $record, \Tablo\Tests\Support\TemporaryDirectory $directory): void
{
    $receipt = \Tablo\Tests\Support\SubprocessReceiptData::checked($record);
    $sequence = array_values(array_filter(array_column($receipt['events'], 'stage'), static fn (string $stage): bool =>
        in_array($stage, ['barrier-input-flushed', 'exit-selection', 'failure', 'root-exit'], strict: true)));
    Assert::same($sequence, ['barrier-input-flushed', 'exit-selection', 'failure', 'root-exit']);
    $selections = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'exit-selection'));
    $failures = array_values(array_filter($receipt['events'], static fn (array $event): bool => $event['stage'] === 'failure'));
    $selection = $selections[0] ?? throw new \RuntimeException('Real exit selection absent.');
    $failure = $failures[0] ?? throw new \RuntimeException('Real exit observation failure absent.');
    Assert::same($selection['timeout_seconds'] ?? null, 0);
    Assert::same([$failure['failure_stage'] ?? null, $failure['type'] ?? null], ['exit-selection', \RuntimeException::class]);
    $directory->close(); Assert::true(is_dir($directory->path));
    foreach (['stdout' => 'closed-outowned-ack', 'stderr' => 'closed-err'] as $name => $expected) {
        $stream = $receipt['streams'][$name] ?? throw new \RuntimeException('Retained failure stream absent.');
        Assert::true(is_file($stream['path'])); Assert::same(file_get_contents($stream['path']), $expected);
        Assert::same(\Tablo\RequiredFile::digest($stream['path']), $stream['sha256']);
    }
}

function engine_fixture_private_controller_parent(): void
{
    $owner = new \Tablo\Tests\Support\TemporaryDirectory('tablo-controller-parent-');
    $parent = $owner->path . '/tablo-server-abcdef0123456789abcdef0123456789/tmp';
    Assert::true(mkdir($parent, permissions: 0o700, recursive: true));
    $child = new \Tablo\Tests\Support\TemporaryDirectory('controller-', $parent);
    try {
        Assert::same(dirname($child->path), $parent); Assert::true(is_dir($child->path));
        Assert::same(file_put_contents($child->path . '/synthetic-marker', data: 'owned'), 5);
        $before = scandir($owner->path); $caught = null;
        try { new \Tablo\Tests\Support\TemporaryDirectory('must-not-create-', $owner->path); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Explicit owned server temporary root refused'); Assert::same(scandir($owner->path), $before);
        Assert::same(file_get_contents($child->path . '/synthetic-marker'), 'owned');
        $child->close(); Assert::false(file_exists($child->path));
    } catch (\Throwable $error) { $owner->retainFailure($error); throw $error; }
    finally { $child->close(); $owner->close(); }
}

/** Exercise actual environment scope/restoration; these fixtures never open an engine or key. */
function engine_fixture_main_and_cross_scope(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-env-scope-');
    $original = engine_fixture_scope_snapshot();
    try {
        $main = ['TABLO_TEST_RUN_NONCE' => bin2hex(random_bytes(16)), 'TABLO_TEST_DB_DRIVER' => 'mysql', 'TABLO_TEST_RUNTIME_ROOT' => $directory->path . '/mysql'];
        $cross = $main + ['TABLO_TEST_CROSS_RUNTIME_ROOT' => $directory->path . '/mariadb'];
        $one = ['mysql' => []]; $two = ['mysql' => [], 'mariadb' => []];
        putenv('TABLO_TEST_CROSS_RUNTIME_ROOT=' . $directory->path . '/foreign-inherited-canary');
        $before = engine_fixture_scope_snapshot(); $calls = 0;
        $operation = static function () use (&$calls): array { $calls++; return engine_fixture_scope_snapshot(); };
        Assert::same(\server_cell_with_environment($main, $operation, $one), $main + ['TABLO_TEST_CROSS_RUNTIME_ROOT' => false]);
        Assert::same($calls, 1); Assert::same(engine_fixture_scope_snapshot(), $before);
        Assert::same(\server_cell_with_environment($cross, $operation, $two), $cross);
        Assert::same(engine_fixture_scope_snapshot(), $before);
        $calls = 0; $caught = null;
        try { \server_cell_with_environment($main, $operation, $two); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class); Assert::same($calls, 0); Assert::same(engine_fixture_scope_snapshot(), $before);
        $caught = null;
        try { \server_cell_with_environment($cross, $operation, $one); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class); Assert::same($calls, 0); Assert::same(engine_fixture_scope_snapshot(), $before);
        foreach ([[$main, $one], [$cross, $two]] as [$environment, $services]) {
            $primary = new \RuntimeException('synthetic callback failed'); $caught = null;
            try { \server_cell_with_environment($environment, static function () use ($primary): never { throw $primary; }, $services); }
            catch (\RuntimeException $error) { $caught = $error; }
            Assert::same($caught, $primary); Assert::same(engine_fixture_scope_snapshot(), $before);
        }
    } finally { engine_fixture_scope_restore($original); $directory->close(); }
}

/** @return array<string, string|false> */
function engine_fixture_scope_snapshot(): array
{
    $values = [];
    foreach (['TABLO_TEST_RUN_NONCE', 'TABLO_TEST_DB_DRIVER', 'TABLO_TEST_RUNTIME_ROOT', 'TABLO_TEST_CROSS_RUNTIME_ROOT'] as $name) { $values[$name] = getenv($name); }
    return $values;
}

/** @param array<string, string|false> $values */
function engine_fixture_scope_restore(array $values): void
{
    foreach ($values as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
}

/** An actual bounded child consumes the original deadline; neither identity path can replenish it. */
function engine_fixture_cleanup_late_identity(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-engine-deadline-');
    try {
        $deadline = hrtime(true) / 1_000_000_000 + 1;
        $remaining = \server_cell_service_remaining($deadline, maximum: 8);
        Assert::true($remaining > 0); Assert::true($remaining <= 1);
        $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, '-r', 'usleep(1200000);fwrite(STDOUT,"CLOSED\n");'], $directory, []);
        Assert::same([$child['exit_code'], $child['stdout'], $child['stderr']], [0, "CLOSED\n", '']);
        $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($child['files']['receipt']);
        Assert::same([$receipt['closed'], $receipt['root_exit_observed'], $receipt['exit_code']], [true, true, 0]);
        Assert::true(hrtime(true) / 1_000_000_000 > $deadline);
        // No Docker tool is supplied: a missing deadline check cannot launch a command or silently pass.
        $context = ['plan' => ['services' => ['mysql' => ['containerId' => str_repeat('a', times: 64), 'networkId' => str_repeat('b', times: 64)]]], 'directory' => $directory, 'environment' => []];
        $expected = 'Owned service startup deadline exceeded.';
        Assert::same(engine_fixture_deadline_refusal(static fn (): array => \server_cell_service_identity($context, engine: 'mysql', deadline: $deadline)), $expected);
        Assert::same(engine_fixture_deadline_refusal(static function () use ($context, $deadline): void { \server_cell_network_identity($context, engine: 'mysql', deadline: $deadline); }), $expected);
        Assert::same(engine_fixture_deadline_refusal(static fn (): float => \server_cell_service_remaining($deadline, maximum: 30)), $expected);
    } finally { $directory->close(); }
}

function engine_fixture_deadline_refusal(\Closure $operation): ?string
{
    try { $operation(); } catch (\RuntimeException $error) { return $error->getMessage(); }
    return null;
}

/** Pure protocol controls: these do not start an observer, open a socket or load a private key. */
function engine_fixture_observer_admission(): void
{
    $key = str_repeat('k', times: 32); $nonce = str_repeat('a', times: 32);
    $payload = base64_encode('{"sequence":4,"closed":true}');
    $frame = ['v' => 1, 'nonce' => $nonce, 'seq' => 1, 'kind' => 'accepted', 'payload' => $payload,
        'mac' => \server_cell_observer_mac($key, direction: 'owner-to-controller', nonce: $nonce, kind: 'accepted', payload: $payload)];
    Assert::same(\server_cell_observer_response(\Tablo\JsonEncoding::encode($frame), $key, $nonce, sequence: 4), ['sequence' => 4, 'closed' => true]);
    foreach ([['nonce' => str_repeat('b', times: 32)], ['seq' => 2], ['payload' => base64_encode('{"sequence":4,"closed":false}')], ['extra' => true]] as $change) {
        $candidate = array_replace($frame, $change);
        Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_observer_response(\Tablo\JsonEncoding::encode($candidate), $key, $nonce, sequence: 4)), \RuntimeException::class);
    }
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_observer_response(\Tablo\JsonEncoding::encode($frame), $key, $nonce, sequence: 5)), \RuntimeException::class);
    Assert::false(hash_equals($frame['mac'], \server_cell_observer_mac($key, direction: 'controller-to-owner', nonce: $nonce, kind: 'accepted', payload: $payload)));
    $result = ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'files' => ['stdout' => '', 'stderr' => '', 'receipt' => '']];
    $receipt = ['pid' => 1, 'exit_code' => 0, 'closed' => true, 'root_exit_observed' => true, 'signaled' => false, 'term_signal' => null];
    Assert::true(\server_cell_observer_closed($result, $receipt));
    foreach ([['root_exit_observed' => null], ['exit_code' => null], ['signaled' => true], ['term_signal' => 9], ['pid' => 0]] as $change) {
        Assert::false(\server_cell_observer_closed($result, array_replace($receipt, $change)));
    }
    Assert::same(\server_cell_observer_port(['port' => 46_399]), 46_399);
    foreach ([8087, 1023, 65_536] as $port) {
        Assert::instanceOf(engine_fixture_observer_error(static fn (): int => \server_cell_observer_port(['port' => $port])), \RuntimeException::class);
    }
}

function engine_fixture_observer_error(\Closure $operation): ?\RuntimeException
{
    try { $operation(); return null; } catch (\RuntimeException $error) { return $error; }
}

/** Actual finite binding admission, without a server, subprocess or copied verifier. */
function engine_fixture_native_binding_set(): void
{
    $root = dirname(__DIR__, levels: 2); $loaded = !function_exists('server_verify_bindings');
    require_once $root . '/tools/verify-server.php';
    require_once $root . '/tools/ServerTestEvidence.php';
    require_once $root . '/tools/BindingsDeclaration.php';
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-native-binding-set-');
    try {
        mkdir($directory->path . '/contracts');
        $declaration = \Tablo\Tools\BindingsDeclaration::read($root . '/contracts/php-bindings.json');
        $map = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($root . '/tools/server-native-tests.json')));
        $bindings = \Tablo\JsonRecord::checked($map['bindings'] ?? null);
        Assert::same(count($declaration['symbols']), 59); Assert::same(count($bindings), 59);
        Assert::same(\Tablo\JsonRecord::checked($bindings['dashboard.self_update'] ?? null), ['kind' => 'entity',
            'main' => ['tests/Server/EngineMigrationFreshTest.php', 'tests/Server/EngineMigrationPreviousTest.php', 'tests/Server/EngineWorkerTest.php', 'tests/Server/EngineTransferTest.php'],
            'cross' => ['tests/ServerCross/EngineCrossTransferTest.php'], 'requiredFamilies' => ['MigrationFresh', 'MigrationPrevious', 'Worker', 'Transfer', 'CrossTransfer']]);
        engine_fixture_binding_declaration($directory->path, $declaration);
        foreach (['main', 'cross'] as $lane) {
            \server_verify_bindings($directory->path, $map, \Tablo\Tools\server_native_cases($map, $lane), $lane);
        }
        $cases = \Tablo\Tools\server_native_cases($map, lane: 'main');
        engine_fixture_invalid_binding_maps($directory->path, $map, $cases);
        engine_fixture_invalid_binding_declarations($directory->path, $map, $cases, $declaration);
        engine_fixture_invalid_binding_counts($directory->path, $map, $cases, $declaration);
    } finally { $directory->close(); if ($loaded) { restore_error_handler(); } }
}

function engine_fixture_binding_declaration(string $root, array $declaration): void
{
    $bytes = \Tablo\JsonEncoding::encode($declaration);
    if (file_put_contents($root . '/contracts/php-bindings.json', $bytes) !== strlen($bytes)) { throw new \RuntimeException('Native binding fixture write failed.'); }
}

/** @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases */
function engine_fixture_invalid_binding_maps(string $root, array $map, array $cases): void
{
    $bindings = \Tablo\JsonRecord::checked($map['bindings'] ?? null);
    $entity = \Tablo\JsonRecord::checked($bindings['dashboard.self_update'] ?? null);
    $missing = $bindings; unset($missing['dashboard.self_update']);
    $extra = $bindings; $extra['dashboard.unexpected'] = $entity;
    $wrongKind = $bindings; $wrongKind['dashboard.self_update'] = array_replace($entity, ['kind' => 'command']);
    $empty = $bindings; $empty['dashboard.self_update'] = array_replace($entity, ['main' => []]);
    $unknown = $bindings; $unknown['dashboard.self_update'] = array_replace($entity, ['main' => ['tests/Server/UnownedTest.php']]);
    foreach ([$missing, $extra, $wrongKind, $empty, $unknown] as $invalid) {
        $candidate = $map; $candidate['bindings'] = $invalid;
        Assert::instanceOf(engine_fixture_observer_error(static function () use ($root, $candidate, $cases): void {
            \server_verify_bindings($root, $candidate, $cases, lane: 'main');
        }), \RuntimeException::class);
    }
}

/** @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases
 * @param array{symbols: list<\Tablo\Tools\BindingSymbol::Symbol>, ...} $declaration */
function engine_fixture_invalid_binding_declarations(string $root, array $map, array $cases, array $declaration): void
{
    $missing = $declaration; $missing['symbols'] = array_slice($declaration['symbols'], offset: 0, length: -1);
    $duplicate = $declaration; $duplicate['symbols'][] = $declaration['symbols'][0] ?? throw new \RuntimeException('First binding fixture absent.');
    foreach ([$missing, $duplicate] as $invalid) {
        engine_fixture_binding_declaration($root, $invalid);
        Assert::instanceOf(engine_fixture_observer_error(static function () use ($root, $map, $cases): void {
            \server_verify_bindings($root, $map, $cases, lane: 'main');
        }), \RuntimeException::class);
    }
}

/** Consistent smaller/larger declaration and consumer pairs still violate the fixed set size.
 * @param array<string, array{identity: string, path: string, minimumAssertions: int, deadlineSeconds: ?int}> $cases
 * @param array{symbols: list<\Tablo\Tools\BindingSymbol::Symbol>, ...} $declaration */
function engine_fixture_invalid_binding_counts(string $root, array $map, array $cases, array $declaration): void
{
    $bindings = \Tablo\JsonRecord::checked($map['bindings'] ?? null);
    $short = $declaration; $short['symbols'] = array_slice($declaration['symbols'], offset: 0, length: 55);
    $shortBindings = array_intersect_key($bindings, array_fill_keys(array_column($short['symbols'], 'id'), value: true));
    $extra = $declaration; $symbol = $declaration['symbols'][0] ?? throw new \RuntimeException('First binding fixture absent.');
    $record = \Tablo\JsonRecord::checked($bindings[$symbol['id']] ?? null); $symbol['id'] = 'dashboard.unexpected';
    $extra['symbols'][] = $symbol; $extraBindings = $bindings; $extraBindings[$symbol['id']] = $record;
    foreach ([[$short, $shortBindings], [$extra, $extraBindings]] as [$invalid, $invalidBindings]) {
        engine_fixture_binding_declaration($root, $invalid); $candidate = $map; $candidate['bindings'] = $invalidBindings;
        Assert::instanceOf(engine_fixture_observer_error(static function () use ($root, $candidate, $cases): void {
            \server_verify_bindings($root, $candidate, $cases, lane: 'main');
        }), \RuntimeException::class);
    }
}

/** Labelled records exercise proof refusal only; they are not an actual Composer/engine run. */
function engine_fixture_composer_phase_closure(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/verify-server.php';
    $nonce = str_repeat('a', times: 32); $phase = str_repeat('b', times: 32);
    $controller = ['rootExitObserved' => true, 'windowsExitUnsigned' => 0, 'exitCode' => 0, 'posixSignal' => null];
    $record = ['schema' => 'tablo/server-observer-phase/v1', 'status' => 'passed', 'kind' => 'baseline', 'cellNonce' => $nonce, 'phaseNonce' => $phase,
        'adapterClose' => ['code' => 0, 'signal' => null, 'closeObserved' => true], 'custody' => ['custody' => ['controller' => $controller]]];
    $plan = ['nonce' => $nonce]; $baseline = ['nonce' => $phase];
    Assert::same(engine_fixture_observer_error(static function () use ($record, $plan, $baseline): void {
        \server_verify_baseline_phase_closed($record, $plan, $baseline);
    }), null);
    $candidates = [array_replace($record, ['kind' => 'engine']), array_replace($record, ['phaseNonce' => $nonce]),
        array_replace($record, ['adapterClose' => ['code' => null, 'signal' => null, 'closeObserved' => true]]),
        array_replace($record, ['adapterClose' => ['code' => 0, 'signal' => null, 'closeObserved' => false]])];
    foreach ([['rootExitObserved' => false], ['windowsExitUnsigned' => null, 'exitCode' => null], ['posixSignal' => 'SIGKILL']] as $change) {
        $candidates[] = array_replace($record, ['custody' => ['custody' => ['controller' => array_replace($controller, $change)]]]);
    }
    foreach ($candidates as $candidate) {
        Assert::instanceOf(engine_fixture_observer_error(static function () use ($candidate, $plan, $baseline): void {
            \server_verify_baseline_phase_closed($candidate, $plan, $baseline);
        }), \RuntimeException::class);
    }
}

/** Actual admission logic; this regression never invokes Docker or claims engine availability. */
function engine_fixture_owned_resource_absence(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $plan = engine_fixture_absence_plan();
    engine_fixture_absence_names($plan);
    $network = \server_cell_absence_name($plan, engine: 'mysql', kind: 'network');
    $receipt = ['closed' => true, 'root_exit_observed' => true, 'exit_code' => 1];
    $result = ['exit_code' => 1, 'stdout' => "[]\n", 'stderr' => 'Error response from daemon: network ' . $network . " not found\n"];
    engine_fixture_absence_accepted($plan, $result, $receipt, $network);
    engine_fixture_absence_refused($result, $receipt, $network);
}

function engine_fixture_absence_plan(): array
{
    $plan = ['nonce' => 'abcdef0123456789abcdef0123456789', 'services' => []];
    foreach (['mysql', 'mariadb'] as $engine) {
        $prefix = 'tablo-i39-abcdef012345-' . $engine;
        $plan['services'][$engine] = ['container' => $prefix, 'network' => $prefix . '-net', 'volume' => $prefix . '-data'];
    }
    return $plan;
}

function engine_fixture_absence_names(array $plan): void
{
    foreach (['mysql', 'mariadb'] as $engine) {
        foreach (['container' => '', 'network' => '-net', 'volume' => '-data'] as $kind => $suffix) {
            Assert::same(\server_cell_absence_name($plan, $engine, $kind), 'tablo-i39-abcdef012345-' . $engine . $suffix);
        }
    }
    foreach ([[$plan, 'mysql', 'unknown'], [$plan, 'postgres', 'network'],
        [array_replace_recursive($plan, ['nonce' => '../foreign']), 'mysql', 'network'],
        [array_replace_recursive($plan, ['services' => ['mysql' => ['network' => 'tablo-i39-abcdef012345-mysql-net-foreign']]]), 'mysql', 'network']] as [$invalid, $engine, $kind]) {
        $caught = null; try { \server_cell_absence_name($invalid, $engine, $kind); } catch (\RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, \RuntimeException::class);
    }
}

/** @param array{exit_code: ?int, stdout: string, stderr: string} $result */
function engine_fixture_absence_accepted(array $plan, array $result, array $receipt, string $network): void
{
    foreach (['container' => ['error: no such object: ', 'Error: No such object: ', 'Error response from daemon: No such container: '],
        'network' => ['Error response from daemon: No such network: '], 'volume' => ['Error response from daemon: No such volume: ']] as $kind => $prefixes) {
        $name = \server_cell_absence_name($plan, engine: 'mysql', kind: $kind);
        foreach ($prefixes as $prefix) { Assert::true(\server_cell_absence_record(array_replace($result, ['stderr' => $prefix . $name . "\n"]), $receipt, $kind, $name)); }
    }
    Assert::true(\server_cell_absence_record($result, $receipt, kind: 'network', name: $network));
    Assert::true(\server_cell_absence_record(array_replace($result, ['stderr' => str_replace("\n", replace: "\r\n", subject: $result['stderr'])]), $receipt, kind: 'network', name: $network));
    $volume = \server_cell_absence_name($plan, engine: 'mysql', kind: 'volume');
    Assert::true(\server_cell_absence_record(array_replace($result, ['stderr' => 'Error response from daemon: get ' . $volume . ": no such volume\n"]), $receipt, kind: 'volume', name: $volume));
}

/** @param array{exit_code: ?int, stdout: string, stderr: string} $result */
function engine_fixture_absence_refused(array $result, array $receipt, string $network): void
{
    foreach (engine_fixture_absence_bad_results($result, $network) as $bad) { Assert::false(\server_cell_absence_record($bad, $receipt, kind: 'network', name: $network)); }
    foreach ([['closed' => false], ['root_exit_observed' => false], ['exit_code' => null], ['exit_code' => 0], ['exit_code' => -1]] as $bad) {
        Assert::false(\server_cell_absence_record($result, array_replace($receipt, $bad), kind: 'network', name: $network));
    }
    Assert::false(\server_cell_absence_record($result, $receipt, kind: 'container', name: $network));
    Assert::false(\server_cell_absence_record($result, $receipt, kind: 'foreign', name: $network));
}

/** @param array{exit_code: ?int, stdout: string, stderr: string} $result
 * @return list<array> */
function engine_fixture_absence_bad_results(array $result, string $network): array
{
    return [array_replace($result, ['exit_code' => 0]), array_replace($result, ['exit_code' => null]), array_replace($result, ['exit_code' => 125]),
        array_replace($result, ['stdout' => '']), array_replace($result, ['stdout' => "[{}]\n"]), array_replace($result, ['stdout' => "[]\n[]\n"]),
        array_replace($result, ['stderr' => '']), array_replace($result, ['stderr' => $result['stderr'] . "\n"]),
        array_replace($result, ['stderr' => $result['stderr'] . 'permission denied']), array_replace($result, ['stderr' => 'Cannot connect to the Docker daemon']),
        array_replace($result, ['stderr' => 'context was not found']), array_replace($result, ['stderr' => 'access denied: ' . $result['stderr']]),
        array_replace($result, ['stderr' => str_replace($network, replace: $network . '-foreign', subject: $result['stderr'])]),
        array_replace($result, ['stderr' => str_replace($network, replace: strtoupper($network), subject: $result['stderr'])]),
        array_replace($result, ['stderr' => str_replace('network ', replace: 'volume ', subject: $result['stderr'])])];
}

/** The controller is loaded from this source, while real children must run from a different prepared copy. */
function engine_fixture_materialized_source_children(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $owner = new \Tablo\Tests\Support\TemporaryDirectory('tablo-materialized-children-');
    $source = $owner->path . '/prepared';
    try {
        foreach (['tools', 'tests'] as $directory) { Assert::true(mkdir($source . '/' . $directory, permissions: 0o700, recursive: true)); }
        $body = '<?php declare(strict_types=1); echo str_replace(chr(92), "/", __FILE__), "\n", $argv[1] ?? "";';
        foreach (['tools/server-cell.php', 'tests/server-child.php'] as $relative) { Assert::same(file_put_contents($source . '/' . $relative, $body), strlen($body)); }
        $context = ['plan' => ['source' => $source]];
        $operations = ['--availability=owned-input', '--initialize=owned-input', '--restart-ready=owned-input', 'exit-after-ddl'];
        foreach ($operations as $operation) {
            $relative = $operation === 'exit-after-ddl' ? 'tests/server-child.php' : 'tools/server-cell.php';
            $script = \server_cell_script($context, $relative);
            Assert::same($script, $source . '/' . $relative);
            $child = \Tablo\Tests\Support\Subprocess::run([PHP_BINARY, $script, $operation], $owner, []);
            Assert::same([$child['exit_code'], $child['stdout'], $child['stderr']], [0, $script . "\n" . $operation, '']);
            $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($child['files']['receipt']);
            Assert::same([$receipt['closed'], $receipt['root_exit_observed'], $receipt['exit_code']], [true, true, 0]);
            Assert::true(is_int($receipt['pid']) && $receipt['pid'] > 0);
        }
        foreach (['../tools/server-cell.php', 'app/bootstrap.php'] as $relative) {
            $caught = null;
            try { \server_cell_script($context, $relative); } catch (\RuntimeException $error) { $caught = $error; }
            Assert::same($caught?->getMessage(), 'Native child script selection refused.');
        }
    } catch (\Throwable $error) { $owner->retainFailure($error); throw $error; }
    finally { $owner->close(); }
}

/** Synthetic files exercise the real freeze/custody helpers without running Composer or an engine. */
function engine_fixture_vendor_autoload_custody(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    require_once dirname(__DIR__, levels: 2) . '/tools/ServerFixturePlan.php';
    $owner = new \Tablo\Tests\Support\TemporaryDirectory('tablo-vendor-custody-'); $root = $owner->path . '/source';
    try {
        $plan = engine_fixture_vendor_autoload_inputs($root);
        $continued = engine_fixture_vendor_autoload_generation($root, $plan);
        engine_fixture_vendor_autoload_mutations($root, $continued);
        engine_fixture_vendor_autoload_missing($root, $plan, $continued);
        engine_fixture_vendor_autoload_records($continued);
    } catch (\Throwable $error) { $owner->retainFailure($error); throw $error; }
    finally { $owner->close(); }
}

/** Independent literal fixture contract, never supplied by generated continuation data.
 * @return list<string> */
function engine_fixture_vendor_generated_names(): array
{ return ['composer/autoload_classmap.php', 'composer/autoload_files.php', 'composer/autoload_static.php']; }

function engine_fixture_vendor_autoload_inputs(string $root): array
{
    foreach (['composer', 'package'] as $name) { Assert::true(mkdir($root . '/vendor/' . $name, permissions: 0o700, recursive: true)); }
    foreach ([...engine_fixture_vendor_generated_names(), 'package/locked.php'] as $name) {
        $bytes = 'initial:' . $name; Assert::same(file_put_contents($root . '/vendor/' . $name, $bytes), strlen($bytes));
    }
    return ['source' => $root, 'vendorFiles' => \Tablo\Tools\server_fixture_plan_vendor_files($root . '/vendor')];
}

function engine_fixture_vendor_autoload_generation(string $root, array $plan): array
{
    foreach (engine_fixture_vendor_generated_names() as $name) {
        $bytes = 'generated:' . $name; Assert::same(file_put_contents($root . '/vendor/' . $name, $bytes), strlen($bytes));
    }
    foreach ([null, 1, 125] as $exit) {
        Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_autoload_freeze($plan, $exit)), \RuntimeException::class);
    }
    $frozen = \server_cell_autoload_freeze($plan, exit: 0);
    Assert::same(array_column($frozen, 'path'), engine_fixture_vendor_generated_names());
    foreach ($frozen as $file) { Assert::same($file['sha256'], \Tablo\RequiredFile::digest($root . '/vendor/' . $file['path'])); }
    $continued = $plan + ['generatedAutoloadFiles' => $frozen];
    Assert::same(\server_cell_vendor_frozen_custody($continued), $frozen);
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_autoload_freeze($continued, exit: 0)), \RuntimeException::class);
    return $continued;
}

function engine_fixture_vendor_autoload_mutations(string $root, array $continued): void
{
    foreach ([...engine_fixture_vendor_generated_names(), 'package/locked.php'] as $name) {
        $path = $root . '/vendor/' . $name; $before = \Tablo\RequiredFile::bytes(file_get_contents($path));
        foreach (['later:' . $name, 'initial:' . $name] as $bytes) {
            if ($bytes === $before) { continue; }
            Assert::same(file_put_contents($path, $bytes), strlen($bytes));
            Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_vendor_frozen_custody($continued)), \RuntimeException::class);
        }
        Assert::same(file_put_contents($path, $before), strlen($before));
    }
    $extra = $root . '/vendor/unlisted.php'; Assert::same(file_put_contents($extra, data: 'extra'), 5);
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_vendor_frozen_custody($continued)), \RuntimeException::class);
    $original = $continued; unset($original['generatedAutoloadFiles']);
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_autoload_freeze($original, exit: 0)), \RuntimeException::class);
    Assert::true(unlink($extra));
}

function engine_fixture_vendor_autoload_missing(string $root, array $plan, array $continued): void
{
    $locked = $root . '/vendor/package/locked.php'; $bytes = \Tablo\RequiredFile::bytes(file_get_contents($locked));
    Assert::same(file_put_contents($locked, data: 'changed-before-freeze'), 21);
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_autoload_freeze($plan, exit: 0)), \RuntimeException::class);
    Assert::same(file_put_contents($locked, $bytes), strlen($bytes));
    foreach (engine_fixture_vendor_generated_names() as $name) {
        $path = $root . '/vendor/' . $name; $before = \Tablo\RequiredFile::bytes(file_get_contents($path)); Assert::true(unlink($path));
        Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_autoload_freeze($plan, exit: 0)), \RuntimeException::class);
        Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_vendor_frozen_custody($continued)), \RuntimeException::class);
        Assert::same(file_put_contents($path, $before), strlen($before));
    }
}

function engine_fixture_vendor_autoload_records(array $continued): void
{
    $frozen = \Tablo\JsonRecord::checked($continued['generatedAutoloadFiles'] ?? null);
    $first = \Tablo\JsonRecord::checked($frozen[0] ?? null);
    $invalid = [null, [], array_slice($frozen, offset: 0, length: 2), [...$frozen, $first],
        array_replace($frozen, [0 => array_replace($first, ['path' => '../outside.php'])]),
        array_replace($frozen, [0 => array_replace($first, ['sha256' => 'invalid'])]),
        array_replace($frozen, [0 => $first + ['unreviewed' => true]])];
    foreach ($invalid as $files) {
        $candidate = array_replace($continued, ['generatedAutoloadFiles' => $files]);
        Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_vendor_frozen_custody($candidate)), \RuntimeException::class);
    }
    unset($continued['generatedAutoloadFiles']);
    Assert::instanceOf(engine_fixture_observer_error(static fn (): array => \server_cell_vendor_frozen_custody($continued)), \RuntimeException::class);
}

/** Authored PDO diagnostics test redaction and typing only; these are never engine-availability evidence. */
function engine_fixture_safe_native_failure_codes(): void
{
    require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
    $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'native-diagnostic-private-canary');
    $native = new \PDOException($secret); $native->errorInfo = ['23000', 1062, $secret];
    $failure = \Tablo\DatabaseFailure::fromNative($native);
    Assert::same([$failure->category, $failure->sqlState, $failure->nativeCode], ['constraint', '23000', 1062]);
    Assert::same($failure->getPrevious(), null);
    Assert::false(str_contains($failure->getMessage() . \Tablo\JsonEncoding::encode($failure->getTrace()), $secret));
    $diagnostic = \server_cell_availability_failure($failure, engine: 'mysql', stage: 'connection');
    Assert::same([$diagnostic['type'], $diagnostic['category'], $diagnostic['sqlState'], $diagnostic['nativeCode']], ['database', 'constraint', '23000', 1062]);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($diagnostic), $secret));
    engine_fixture_safe_engine_failure($failure, $secret);
    $invalid = [[[$secret, 2002, $secret], [null, 2002]], [['HY000', '2002', $secret], ['HY000', null]],
        [['HY000', 65_536, $secret], ['HY000', null]], [['HY000', -1, $secret], ['HY000', null]], [[null, null, $secret], [null, null]]];
    foreach ($invalid as [$information, $expected]) {
        $native->errorInfo = $information; $failure = \Tablo\DatabaseFailure::fromNative($native);
        Assert::same([$failure->sqlState, $failure->nativeCode], $expected);
        Assert::same($failure->getPrevious(), null);
        Assert::false(str_contains($failure->getMessage() . \Tablo\JsonEncoding::encode($failure->getTrace()), $secret));
    }
    engine_fixture_safe_native_reasons($secret);
}

/** Same authored PDO object, fixed enum only; arbitrary private text and malformed information remain unknown. */
function engine_fixture_safe_native_reasons(#[\SensitiveParameter] string $secret): void
{
    $valid = [['Connection refused', 'connection-refused'],
        ['No connection could be made because the target machine actively refused it', 'connection-refused'],
        ['SSL connection error: certificate verify failed', 'certificate-verification'],
        ['Cannot connect to MySQL by using SSL', 'ssl-handshake'],
        ['php_network_getaddresses: getaddrinfo for ' . $secret . ' failed: No such host is known', 'name-resolution']];
    foreach ($valid as [$message, $expected]) { engine_fixture_safe_reason_record($secret, ['HY000', 2002, $message], $expected); }
    foreach ([['HY000', 2002, $secret], ['HY000', 2002, 'Connection refused' . $secret], ['HY000', 2002, $secret . ' certificate verify failed'],
        ['HY000', '2002', 'Connection refused'], ['bad-state', 2002, 'Connection refused'], ['HY000', 2002, [$secret]],
        ['HY000', 2002], ['HY000', 1062, 'Connection refused']] as $invalid) {
        engine_fixture_safe_reason_record($secret, $invalid, expected: 'unknown');
    }
    $failure = new \Tablo\DatabaseFailure('unavailable', nativeReason: $secret);
    Assert::same($failure->nativeReason, 'unknown'); Assert::null($failure->getPrevious());
    Assert::false(str_contains(\Tablo\JsonEncoding::encode(['failure' => $failure, 'trace' => $failure->getTrace()]), $secret));
}

function engine_fixture_safe_reason_record(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] array $information, string $expected): void
{
    $native = new \PDOException($secret); $native->errorInfo = $information;
    $failure = \Tablo\DatabaseFailure::fromNative($native);
    Assert::same([$failure->nativeReason, $failure->getMessage()], [$expected, ($information[1] ?? null) === 1062 ? 'Database constraint refused.' : 'Database unavailable.']);
    Assert::null($failure->getPrevious());
    $diagnostic = \server_cell_availability_failure($failure, engine: 'mysql', stage: 'connection');
    Assert::same($diagnostic['nativeReason'], $expected);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode(['failure' => $failure, 'trace' => $failure->getTrace(), 'diagnostic' => $diagnostic]), $secret));
    engine_fixture_safe_engine_failure($failure, $secret);
}

function engine_fixture_safe_engine_failure(\Tablo\DatabaseFailure $failure, #[\SensitiveParameter] string $secret): void
{
    $record = \server_cell_engine_failure($failure);
    Assert::same([$record['schema'], $record['status'], $record['phase'], $record['rootCause'], $record['issueAccepted']],
        ['tablo/server-engine-failure/v1', 'failed', 'engine', 'UNKNOWN', false]);
    Assert::same([$record['type'], $record['category'], $record['sqlState'], $record['nativeCode'], $record['nativeReason']],
        ['database', $failure->category, $failure->sqlState, $failure->nativeCode, $failure->nativeReason]);
    $bytes = \Tablo\JsonEncoding::encode($record);
    Assert::true(strlen($bytes) < 1_024); Assert::false(str_contains($bytes, $secret));
    Assert::false(array_key_exists('message', $record)); Assert::false(array_key_exists('trace', $record));
    $marker = \server_cell_availability_failure($failure, engine: 'mariadb', stage: 'connection');
    $observation = \server_cell_availability_observation(['exit_code' => 1, 'stdout' => '',
        'stderr' => json_encode($marker, JSON_THROW_ON_ERROR) . "\nNative server cell failed.\n"]);
    Assert::true(is_array($observation)); $observation ??= throw new \RuntimeException('Expected availability observation.');
    Assert::same($observation['diagnostic'], $marker);
    Assert::same([$observation['reportedExit'], $observation['stdout'], $observation['stderr']], [1, 'EMPTY', 'NONEMPTY']);
    Assert::false(str_contains(\Tablo\JsonEncoding::encode($observation), $secret));
}

/** Authored inspect records exercise admission only; these controls never invoke Docker or prove availability. */
function engine_fixture_loopback_publication(): void
{
    $binding = ['HostIp' => '127.0.0.1', 'HostPort' => '46394']; $mapping = ['3306/tcp' => [$binding]];
    $record = ['HostConfig' => ['PortBindings' => $mapping], 'NetworkSettings' => ['Ports' => $mapping]];
    $expected = ['requestedPresent' => true, 'requested' => $mapping, 'actualPresent' => true, 'actual' => $mapping];
    Assert::same(\server_cell_publication_fields($record), $expected);
    Assert::same(\server_cell_require_publication($record, port: 46_394), $expected);
    $reordered = ['HostPort' => '46394', 'HostIp' => '127.0.0.1'];
    $reorderedRecord = ['NetworkSettings' => ['Ports' => ['3306/tcp' => [$reordered]]], 'HostConfig' => ['PortBindings' => ['3306/tcp' => [$reordered]]]];
    Assert::same(\server_cell_require_publication($reorderedRecord, port: 46_394), \server_cell_publication_fields($reorderedRecord));
    engine_fixture_unpublished_ports($record);
    foreach (['HostConfig' => 'PortBindings', 'NetworkSettings' => 'Ports'] as $section => $field) {
        foreach (engine_fixture_invalid_port_mappings($binding) as $mapping) {
            $invalid = $record; $invalid[$section] = [$field => $mapping]; engine_fixture_publication_refused($invalid);
        }
    }
    foreach ([[], ['HostConfig' => null, 'NetworkSettings' => $record['NetworkSettings']],
        ['HostConfig' => $record['HostConfig'], 'NetworkSettings' => null], ['HostConfig' => [], 'NetworkSettings' => []]] as $invalid) {
        engine_fixture_publication_refused($invalid);
    }
    engine_fixture_publication_refused($record, port: 0); engine_fixture_publication_refused($record, port: 65_536);
}

/** Only null additional actual entries are metadata; requested entries stay exact.
 * @param array{HostConfig: array{PortBindings: array}, NetworkSettings: array{Ports: array}} $record */
function engine_fixture_unpublished_ports(array $record): void
{
    foreach ([['33060/tcp' => null], ['33060-33061/tcp' => null], ['1/udp' => null, '65535/tcp' => null, '33061/tcp' => null]] as $extra) {
        $actual = $record; $actual['NetworkSettings']['Ports'] += $extra;
        Assert::same(\server_cell_require_publication($actual, port: 46_394), \server_cell_publication_fields($actual));
        $actual['NetworkSettings']['Ports'] = array_reverse($actual['NetworkSettings']['Ports'], preserve_keys: true);
        Assert::same(\server_cell_require_publication($actual, port: 46_394), \server_cell_publication_fields($actual));
        $requested = $record; $requested['HostConfig']['PortBindings'] += $extra;
        engine_fixture_publication_refused($requested);
    }
    foreach (['', 'other/tcp', '0/tcp', '65536/tcp', '033060/tcp', '33060/sctp', '33060', '33061-33060/tcp', '33060-65536/tcp',
        '33060-033061/tcp', "33060/tcp\n", '33060/tcp/private', 0] as $name) {
        $actual = $record; $actual['NetworkSettings']['Ports'][$name] = null; engine_fixture_publication_refused($actual);
    }
    foreach ([false, '', [], [null], [['HostIp' => '127.0.0.1', 'HostPort' => '46394']], [['HostIp' => '0.0.0.0', 'HostPort' => '46394']]] as $value) {
        $actual = $record; $actual['NetworkSettings']['Ports']['33060/tcp'] = $value; engine_fixture_publication_refused($actual);
    }
    $actual = $record;
    for ($index = 1; $index <= 127; $index++) { $actual['NetworkSettings']['Ports'][$index . '/udp'] = null; }
    Assert::same(\server_cell_require_publication($actual, port: 46_394), \server_cell_publication_fields($actual));
    $actual = $record;
    for ($index = 1; $index <= 128; $index++) { $actual['NetworkSettings']['Ports'][$index . '/udp'] = null; }
    engine_fixture_publication_refused($actual);
}

/** @return list<array|false|null|string> */
function engine_fixture_invalid_port_mappings(array $binding): array
{
    return [null, false, 'malformed', [], ['other/tcp' => [$binding]], ['3306/tcp' => null], ['3306/tcp' => []], ['3306/tcp' => [null]],
        ['3306/tcp' => [$binding, $binding]], ['3306/tcp' => [array_replace($binding, ['HostIp' => '0.0.0.0'])]],
        ['3306/tcp' => [array_replace($binding, ['HostIp' => '::1'])]], ['3306/tcp' => [$binding, array_replace($binding, ['HostIp' => '::1'])]],
        ['3306/tcp' => [array_replace($binding, ['HostPort' => '46393'])]], ['3306/tcp' => [array_replace($binding, ['HostPort' => 46_394])]],
        ['3306/tcp' => [$binding + ['extra' => 'refused']]], ['3306/tcp' => [$binding], '3307/tcp' => [$binding]],
        ['3306/tcp' => ['named-row' => $binding]]];
}

function engine_fixture_publication_refused(array $record, int $port = 46_394): void
{
    $caught = null;
    try { \server_cell_require_publication($record, $port); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::same($caught?->getMessage(), 'Exact loopback port publication not observed.');
}

/** Pure authored context/closure/argv controls. No profile/config/daemon is read and no command is launched. */
function engine_fixture_context_admission(): void
{
    $directory = new \Tablo\Tests\Support\TemporaryDirectory('tablo-context-admission-');
    try {
        foreach (['Windows' => ['desktop-linux', 'npipe:////./pipe/dockerDesktopLinuxEngine'], 'Linux' => ['default', 'unix:///var/run/docker.sock']] as $platform => [$name, $endpoint]) {
            $runtime = $directory->path . '/tablo-server-abcdef0123456789abcdef0123456789';
            $plan = ['runtime' => $runtime, 'dockerPlatform' => $platform, 'dockerContext' => $name, 'dockerEndpoint' => $endpoint,
                'dockerContextConfig' => $directory->path . '/context-source/.docker', 'dockerConfig' => $runtime . '/private/docker-config', 'docker' => ['path' => 'never-executed-docker']];
            $record = ['Name' => $name, 'Endpoints' => ['docker' => ['Host' => $endpoint, 'SkipTLSVerify' => false]], 'TLSMaterial' => []];
            $selection = ['context' => $name, 'endpoint' => $endpoint, 'platform' => $platform, 'contextConfig' => $plan['dockerContextConfig'], 'privateConfig' => $plan['dockerConfig']];
            $result = ['exit_code' => 0, 'stderr' => '', 'stdout' => \Tablo\JsonEncoding::encode([$record])];
            $receipt = ['pid' => 42, 'exit_code' => 0, 'closed' => true, 'root_exit_observed' => true, 'signaled' => false, 'term_signal' => null];
            Assert::same(\server_cell_context_metadata($plan, $result['stdout'], $platform), $selection);
            Assert::same(\server_cell_context_observation($plan, $result, $receipt, $platform), $selection);
            $admitted = $selection + ['metadataSha256' => str_repeat('a', times: 64), 'closureSha256' => str_repeat('b', times: 64)];
            $context = ['plan' => $plan + ['dockerAdmission' => $admitted], 'environment' => ['SAFE' => 'synthetic', 'DOCKER_HOST' => 'tcp://foreign:2375', 'Docker_Context' => 'foreign', 'DOCKER_CONFIG' => 'foreign']];
            Assert::same(\server_cell_docker_arguments($context, ['inspect', 'owned-id'], $platform), ['never-executed-docker', '--config', $plan['dockerConfig'], '--host', $endpoint, 'inspect', 'owned-id']);
            Assert::same(\server_cell_docker_environment($context), ['SAFE' => 'synthetic', 'DOCKER_CONFIG' => $plan['dockerConfig']]);
            engine_fixture_context_negative_controls($plan, $record, $result, $receipt, $platform);
        }
    } finally { $directory->close(); }
}

function engine_fixture_context_negative_controls(array $plan, array $record, array $result, array $receipt, string $platform): void
{
    $admitted = \server_cell_context_declaration($plan, $platform) + ['metadataSha256' => str_repeat('a', times: 64), 'closureSha256' => str_repeat('b', times: 64)];
    foreach (engine_fixture_context_bad_metadata($record) as $stdout) {
        engine_fixture_context_refused(static fn (): array => \server_cell_context_metadata($plan, $stdout, $platform));
    }
    foreach ([['closed' => false], ['root_exit_observed' => false], ['exit_code' => null], ['exit_code' => 1], ['signaled' => true], ['term_signal' => 9], ['pid' => null]] as $bad) {
        engine_fixture_context_refused(static fn (): array => \server_cell_context_observation($plan, $result, array_replace($receipt, $bad), $platform));
    }
    foreach ([null, 1] as $exit) { engine_fixture_context_refused(static fn (): array => \server_cell_context_observation($plan, array_replace($result, ['exit_code' => $exit]), $receipt, $platform)); }
    foreach ([['dockerPlatform' => 'Darwin'], ['dockerContext' => 'foreign'], ['dockerEndpoint' => 'ssh://foreign'], ['dockerConfig' => '/foreign'], ['dockerContextConfig' => '']] as $bad) {
        engine_fixture_context_refused(static fn (): array => \server_cell_context_metadata(array_replace($plan, $bad), \Tablo\SqlColumn::string($result, 'stdout'), $platform));
    }
    foreach ([null, array_replace($admitted, ['endpoint' => 'tcp://foreign:2375']), array_replace($admitted, ['context' => 'foreign']),
        array_replace($admitted, ['metadataSha256' => 'unknown']), array_replace($admitted, ['closureSha256' => null])] as $bad) {
        $context = ['plan' => $plan + ['dockerAdmission' => $bad]];
        engine_fixture_context_refused(static fn (): array => \server_cell_docker_arguments($context, ['inspect', 'owned-id'], $platform));
    }
}

/** @return list<string> */
function engine_fixture_context_bad_metadata(array $record): array
{
    $changes = [array_replace($record, ['Name' => 'foreign']), array_replace($record, ['Endpoints' => []]),
        array_replace_recursive($record, ['Endpoints' => ['docker' => ['Host' => 'tcp://foreign:2375']]]),
        array_replace_recursive($record, ['Endpoints' => ['docker' => ['SkipTLSVerify' => true]]]),
        array_replace_recursive($record, ['Endpoints' => ['foreign' => []]]), array_replace($record, ['TLSMaterial' => ['foreign' => []]])];
    $values = ['', '{', '[]', \Tablo\JsonEncoding::encode([$record, $record]), str_repeat(' ', times: 65_537)];
    foreach ($changes as $change) { $values[] = \Tablo\JsonEncoding::encode([$change]); }
    return $values;
}

function engine_fixture_context_refused(\Closure $operation): void
{
    $caught = null; try { $operation(); } catch (\RuntimeException $error) { $caught = $error; }
    Assert::instanceOf($caught, \RuntimeException::class);
}
