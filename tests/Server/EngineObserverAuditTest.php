<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use RuntimeException;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;
use Throwable;

/** File-only evidence validation; authored records are never actual native process proof.
 * @phpstan-type Stream array{path: string, sha256: string}
 * @phpstan-type Streams array{stdout: Stream, stderr: Stream}
 * @phpstan-type Event array{utc: string, stage: string, argument_count?: int, failure_stage?: string, type?: string, exit_code?: int, monotonic_ns?: int}
 * @phpstan-type Events list{Event, Event, Event}
 * @phpstan-type Receipt array{pid: ?int, exit_code: ?int, root_exit_observed: bool, platform: string, signaled: bool, term_signal: ?int, proc_close?: ?int, closed: bool, ownership: string, stream_eof: string, events: Events, streams: Streams}
 * @phpstan-type Record array{pid?: ?int, sourceReceipt: string, receiptCopy: string, receiptSha256: string, streams: Streams}
 * @phpstan-type State array{receipt: Receipt, record: Record, start: string, close: string, copy: string}
 * @phpstan-type Fixture array{directory: TemporaryDirectory, runtime: string, sink: string}
 */
final class EngineObserverAuditTest
{
    #[Test]
    public function startedChildrenAndClosedNoStartAttemptsHaveSeparateCustodyCounts(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
        $fixture = engine_observer_audit_fixture();
        try {
            engine_observer_audit_add($fixture, pid: 17);
            engine_observer_audit_real_no_start($fixture);
            $valueError = engine_observer_audit_add($fixture);
            $events = $valueError['receipt']['events'];
            $events[1]['type'] = 'ValueError';
            foreach (array_keys($events) as $index) { $events[$index]['monotonic_ns'] = 100 + $index; }
            engine_observer_audit_receipt($valueError, ['events' => $events, 'raw_status' => null]);
            $summary = \server_cell_observer_audit($fixture['sink']);
            Assert::same($summary['starts'], 1); Assert::same($summary['closes'], 1);
            Assert::true($summary['nativeClosureKnown']);
            Assert::same($summary['nativeClosureScope'], 'positively registered started children only');
            Assert::same($summary['noStartAttempts'], 2); Assert::same(count($summary['noStart']), 2);
            foreach ($summary['noStart'] as $attempt) {
                Assert::null($attempt['pid']); Assert::null($attempt['exit_code']); Assert::null($attempt['proc_close']);
                Assert::false($attempt['root_exit_observed']);
                Assert::same($attempt['classification'], 'closed reported no-process-capability attempt; native exit not inferred');
            }
        } catch (Throwable $error) { $fixture['directory']->retainFailure($error); throw $error; }
        finally { $fixture['directory']->close(); }
    }

    #[Test]
    public function unmatchedDuplicateMalformedAndTamperedEvidenceNeverSettlesAnAudit(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/server-cell.php';
        foreach (engine_observer_audit_hostile_cases() as $name => $change) {
            $fixture = engine_observer_audit_fixture();
            try {
                $started = engine_observer_audit_add($fixture, pid: 17); $attempt = engine_observer_audit_add($fixture);
                $change($fixture, $started, $attempt);
                $refused = false;
                try { \server_cell_observer_audit($fixture['sink']); } catch (RuntimeException) { $refused = true; }
                Assert::true($refused, $name);
            } catch (Throwable $error) { $fixture['directory']->retainFailure($error); throw $error; }
            finally { $fixture['directory']->close(); }
        }
    }
}

/** @return EngineObserverAuditTest::Fixture */
function engine_observer_audit_fixture(): array
{
    $directory = new TemporaryDirectory('tablo-observer-audit-');
    $runtime = $directory->path . '/tablo-server-' . bin2hex(random_bytes(16));
    $sink = $runtime . '/evidence/closure';
    foreach ([$runtime, $runtime . '/evidence', $sink, $runtime . '/tmp'] as $path) {
        if (!mkdir($path, permissions: 0o700)) { throw new RuntimeException('Cannot create synthetic observer evidence.'); }
    }
    return ['directory' => $directory, 'runtime' => $runtime, 'sink' => $sink];
}

/** The original malformed-argument control calls the real producer but cannot create a native child.
 * @param EngineObserverAuditTest::Fixture $fixture */
function engine_observer_audit_real_no_start(array $fixture): void
{
    $directory = new TemporaryDirectory('tablo-real-no-start-', $fixture['runtime'] . '/tmp');
    $before = getenv('TABLO_SESSION_EVIDENCE_DIRECTORY'); $caught = null;
    try {
        Assert::true(putenv('TABLO_SESSION_EVIDENCE_DIRECTORY=' . $fixture['sink']));
        try { \Tablo\Tests\Support\Subprocess::run(["invalid\0synthetic-argv-secret"], $directory, []); }
        catch (RuntimeException $error) { $caught = $error; }
        Assert::instanceOf($caught, RuntimeException::class);
        $message = $caught->getMessage();
        Assert::true(str_contains($message, 'stage=spawn')); Assert::false(str_contains($message, 'synthetic-argv-secret'));
        engine_observer_audit_real_no_start_receipt($directory);
    } finally {
        putenv($before === false ? 'TABLO_SESSION_EVIDENCE_DIRECTORY' : 'TABLO_SESSION_EVIDENCE_DIRECTORY=' . $before);
        $directory->close();
    }
}

function engine_observer_audit_real_no_start_receipt(TemporaryDirectory $directory): void
{
    $files = \Tablo\Tests\Support\FixtureFiles::matches($directory->path . '/command-*.json'); Assert::same(count($files), 1);
    $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($files[0] ?? throw new RuntimeException('Actual failed-spawn receipt missing.'));
    Assert::same(array_column($receipt['events'], 'stage'), ['spawn', 'failure', 'closed']);
    $failure = \Tablo\JsonRecord::checked($receipt['events'][1] ?? null);
    Assert::same($failure['failure_stage'] ?? null, 'spawn');
    Assert::true(in_array($failure['type'] ?? null, ['RuntimeException', 'ValueError'], strict: true));
    Assert::same([$receipt['pid'], $receipt['exit_code'], $receipt['proc_close'] ?? null, $receipt['root_exit_observed']], [null, null, null, false]);
    \server_cell_observer_no_start($receipt);
}

/** @param EngineObserverAuditTest::Fixture $fixture
 * @return EngineObserverAuditTest::State */
function engine_observer_audit_add(array $fixture, ?int $pid = null): array
{
    $source = $fixture['runtime'] . '/tmp/fixture/command-' . bin2hex(random_bytes(12)) . '.json';
    $copy = $fixture['sink'] . '/direct-child-' . hash('sha256', $source) . '.receipt';
    $digest = hash('sha256', data: ''); $stem = substr($source, offset: 0, length: -5);
    $streams = ['stdout' => ['path' => $stem . '.out', 'sha256' => $digest], 'stderr' => ['path' => $stem . '.err', 'sha256' => $digest]];
    $copies = ['stdout' => ['path' => $copy . '.stdout', 'sha256' => $digest], 'stderr' => ['path' => $copy . '.stderr', 'sha256' => $digest]];
    foreach ($copies as $stream) { engine_observer_audit_write($stream['path'], value: ''); }
    $receipt = engine_observer_audit_receipt_data($pid, $streams);
    $record = ['pid' => $pid, 'sourceReceipt' => $source, 'receiptCopy' => $copy, 'receiptSha256' => '', 'streams' => $copies];
    $start = $fixture['sink'] . '/direct-child-start-' . bin2hex(random_bytes(12)) . '.json';
    $close = $fixture['sink'] . '/direct-child-close-' . bin2hex(random_bytes(12)) . '.json';
    if ($pid !== null) { engine_observer_audit_write($start, ['pid' => $pid, 'sourceReceipt' => $source]); }
    $state = ['receipt' => $receipt, 'record' => $record, 'start' => $start, 'close' => $close, 'copy' => $copy];
    engine_observer_audit_receipt($state, []);
    $state['record']['receiptSha256'] = \Tablo\RequiredFile::digest($copy);
    return $state;
}

/** @param EngineObserverAuditTest::State $state */
function engine_observer_audit_receipt(array $state, array $changes): void
{
    $receipt = array_replace($state['receipt'], $changes);
    engine_observer_audit_write($state['copy'], $receipt);
    engine_observer_audit_record($state, ['receiptSha256' => \Tablo\RequiredFile::digest($state['copy'])]);
}

/** @param EngineObserverAuditTest::State $state */
function engine_observer_audit_record(array $state, array $changes, ?string $remove = null): void
{
    $record = array_replace($state['record'], $changes);
    if ($remove !== null) { unset($record[$remove]); }
    engine_observer_audit_write($state['close'], $record);
}

function engine_observer_audit_write(string $path, array|string $value): void
{
    $bytes = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
    if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Synthetic observer evidence write failed.'); }
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_hostile_cases(): array
{ return engine_observer_audit_union_cases() + engine_observer_audit_state_cases() + engine_observer_audit_event_cases()
    + engine_observer_audit_failure_event_cases() + engine_observer_audit_time_cases() + engine_observer_audit_custody_cases(); }

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_union_cases(): array
{
    return [
       'missing positive close' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { unlink($s['close']); },
        'extra positive close' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $extra = engine_observer_audit_add($f, pid: 17); unlink($extra['start']); },
        'duplicate start' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { copy($s['start'], $f['sink'] . '/direct-child-start-duplicate.json'); },
        'duplicate close' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { copy($a['close'], $f['sink'] . '/direct-child-close-duplicate.json'); },
        'started attempt cannot become no-start' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void {
            engine_observer_audit_receipt($s, ['pid' => null, 'exit_code' => null, 'root_exit_observed' => false, 'proc_close' => null, 'events' => $a['receipt']['events']]);
            $record = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($s['close']))); $record['pid'] = null; engine_observer_audit_write($s['close'], $record);
        },
        'PID mismatch' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_record($s, ['pid' => 18]); },
        'missing record PID' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_record($a, [], remove: 'pid'); },
    ];
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_state_cases(): array
{
    return [
        'unknown exit cannot become zero' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['exit_code' => 0]); },
        'unknown native close cannot become zero' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['proc_close' => 0]); },
        'no-start root exit contradiction' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['root_exit_observed' => true]); },
        'attempt not closed' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['closed' => false]); },
        'signal is not no-start' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['signaled' => true, 'term_signal' => 15]); },
        'raw native status is not no-start' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_receipt($a, ['raw_status' => ['running' => true]]); },
    ];
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_event_cases(): array
{
    return [
        'reordered stages' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[1]['stage'] = 'closed'; engine_observer_audit_receipt($a, ['events' => $events]); },
        'extra lifecycle event' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[] = $events[2]; engine_observer_audit_receipt($a, ['events' => $events]); },
        'missing exception type' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; unset($events[1]['type']); engine_observer_audit_receipt($a, ['events' => $events]); },
        'unreviewed exception type' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[1]['type'] = 'PDOException'; engine_observer_audit_receipt($a, ['events' => $events]); },
        'invalid event time' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[0]['utc'] = 'unknown'; engine_observer_audit_receipt($a, ['events' => $events]); },
        'extra native exit event field' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[2]['exit_code'] = 0; engine_observer_audit_receipt($a, ['events' => $events]); },
        'partial monotonic metadata' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[0]['monotonic_ns'] = 100; engine_observer_audit_receipt($a, ['events' => $events]); },
    ];
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_failure_event_cases(): array
{
    return [
        'missing failure stage' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; unset($events[1]['failure_stage']); engine_observer_audit_receipt($a, ['events' => $events]); },
        'another failure stage is not failed spawn' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[1]['failure_stage'] = 'timeout'; engine_observer_audit_receipt($a, ['events' => $events]); },
        'old second spawn event is refused' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $events = $a['receipt']['events']; $events[1]['stage'] = 'spawn'; engine_observer_audit_receipt($a, ['events' => $events]); },
    ];
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_time_cases(): array
{
    $cases = [];
    foreach ([0, 1, 2] as $index) {
        $cases['missing event utc ' . $index] = /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a) use ($index): void {
            $events = $a['receipt']['events'];
            if (!array_key_exists($index, $events)) { throw new RuntimeException('Synthetic event index missing.'); }
            unset($events[$index]['utc']); engine_observer_audit_receipt($a, ['events' => $events]);
        };
    }
    foreach (['2026-10-18T00:00:00Z', '2026-10-18T00:00:00+01:00', ''] as $utc) {
        $cases['noncanonical event utc ' . $utc] = /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a) use ($utc): void {
            $events = $a['receipt']['events']; $events[1]['utc'] = $utc; engine_observer_audit_receipt($a, ['events' => $events]);
        };
    }
    return $cases;
}

/** @return array<string, \Closure(EngineObserverAuditTest::Fixture, EngineObserverAuditTest::State, EngineObserverAuditTest::State): void> */
function engine_observer_audit_custody_cases(): array
{
    return [
        'source and copied name differ' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void {
            $source = $a['record']['sourceReceipt'];
            engine_observer_audit_record($a, ['sourceReceipt' => substr($source, offset: 0, length: -6) . ($source[-6] === 'a' ? 'b' : 'a') . '.json']);
        },
        'receipt digest tampering' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_write($a['copy'], value: 'tampered'); },
        'stream bytes tampering' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { engine_observer_audit_write($a['copy'] . '.stdout', value: 'tampered'); },
        'stream path tampering' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $streams = $a['record']['streams']; $streams['stderr']['path'] .= '.foreign'; engine_observer_audit_record($a, ['streams' => $streams]); },
        'stream digest tampering' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $streams = $a['record']['streams']; $streams['stdout']['sha256'] = str_repeat('a', times: 64); engine_observer_audit_record($a, ['streams' => $streams]); },
        'original stream source mismatch' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $streams = $a['receipt']['streams']; $streams['stdout']['path'] .= '.foreign'; engine_observer_audit_receipt($a, ['streams' => $streams]); },
        'missing native close fact' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { $receipt = $a['receipt']; unset($receipt['proc_close']); engine_observer_audit_write($a['copy'], $receipt); engine_observer_audit_record($a, ['receiptSha256' => \Tablo\RequiredFile::digest($a['copy'])]); },
        'no positively started children' => /** @param EngineObserverAuditTest::Fixture $f
             * @param EngineObserverAuditTest::State $s
             * @param EngineObserverAuditTest::State $a */ static function (array $f, array $s, array $a): void { unlink($s['start']); unlink($s['close']); },
    ];
}


/** @param EngineObserverAuditTest::Streams $streams
 * @return EngineObserverAuditTest::Receipt */
function engine_observer_audit_receipt_data(?int $pid, array $streams): array
{
    $started = $pid !== null;
    return ['pid' => $pid, 'exit_code' => $started ? 0 : null, 'root_exit_observed' => $started,
        'platform' => PHP_OS_FAMILY, 'signaled' => false, 'term_signal' => null, 'proc_close' => $started ? 0 : null,
        'closed' => true, 'ownership' => 'direct-child-only', 'stream_eof' => 'not used; file backed',
        'events' => engine_observer_audit_events($pid), 'streams' => $streams];
}

/** @return EngineObserverAuditTest::Events */
function engine_observer_audit_events(?int $pid): array
{
    $started = $pid !== null;
    return [['utc' => '2026-10-18T00:00:00+00:00', 'stage' => 'spawn', 'argument_count' => 1],
            ['utc' => '2026-10-18T00:00:00+00:00', 'stage' => $started ? 'exit' : 'failure', ...($started ? ['exit_code' => 0] : ['failure_stage' => 'spawn', 'type' => 'RuntimeException'])],
            ['utc' => '2026-10-18T00:00:00+00:00', 'stage' => 'closed']];
}
