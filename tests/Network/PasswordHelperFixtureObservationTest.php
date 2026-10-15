<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use ReflectionMethod;
use RuntimeException;
use Throwable;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\PasswordHelperCase;
use Tablo\Tests\Support\PasswordHelperEvidence;
use Tablo\Tests\Support\PasswordHelperEvidenceFiles;
use Tablo\Tests\Support\PasswordHelperEvidenceStart;
use Tablo\Tests\Support\PasswordHelperFixtureObservation;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

final class PasswordHelperFixtureObservationTest
{
    private static function shell(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') { throw new SkipTest('Native Windows PowerShell fixture control required.'); }
        return FixtureValue::string(getenv('SystemRoot')) . '/System32/WindowsPowerShell/v1.0/powershell.exe';
    }

    #[Test]
    public function preHelperStallEmitsEntryWhileNativeStagesRemainUnobserved(): void
    {
        $shell = self::shell();
        $directory = null;
        $evidence = null;
        $child = null;
        $primary = null;
        try {
            $directory = new TemporaryDirectory('tablo-helper-fixture-stall-');
            $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::fixtureStall($shell));
            $startedNs = FixtureValue::integer(hrtime(true));
            $child = new Subprocess(PasswordHelperCommand::forEvidence($evidence), $directory);
            $child->send('');
            PasswordHelperEvidenceStart::awaitFixtureEntry($evidence->fixturePath,
                PasswordHelperFixtureObservation::caseMetadata(basename($evidence->path), $evidence->case->identity), $startedNs);
            $error = self::rejection(static fn() => $child->finish(0.2));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'stage=timeout'));
            $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($evidence->path . '/final.receipt.json');
            PasswordHelperEvidence::assertTerminated($receipt);
            Assert::true(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stdout')),
                'HELPER_FIXTURE_CONTROL=pre-helper-stall'));
            $entry = PasswordHelperFixtureObservation::records($evidence->fixturePath);
            Assert::same(array_column($entry, 'stage'), ['fixture-entry']);
            Assert::same(FixtureValue::at(FixtureValue::at($entry, 0), 'wrapperPid'), $receipt['pid']);
            $status = PasswordHelperFixtureObservation::status($evidence,
                \Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stderr')));
            Assert::same($status['fixture'], ['configured' => true, 'state' => 'emitted']);
            Assert::same($status['native'], ['configured' => true, 'state' => 'not-observed']);
            Assert::false(is_file($evidence->nativePath), 'controlled stall precedes helper; absence elsewhere proves no stage');
            $evidence->assertCollected();
        } catch (Throwable $error) {
            $primary = $error; $directory?->retainFailure($error); $evidence?->failure($error); throw $error;
        } finally {
            try { $child?->close($primary); }
            finally { PasswordHelperFixtureObservation::cleanupDirectory($directory); }
        }
    }

    #[Test]
    public function observerDiagnosticFailurePreservesCallerAndRejectsIncompleteCollection(): void
    {
        $shell = self::shell();
        $directory = null;
        try {
            $directory = new TemporaryDirectory('tablo-helper-observer-failure-');
            $caseId = 'case-' . bin2hex(random_bytes(12));
            $case = PasswordHelperFixtureObservation::caseMetadata($caseId,
                ['encoding' => 'default', 'scenario' => 'unicode', 'phase' => 'primary']);
            $rejected = static function (string $journal) use ($case, $directory): void {
                $error = self::rejection(static fn() => PasswordHelperFixtureObservation::assertComplete($journal, $case));
                Assert::instanceOf($error, RuntimeException::class);
                Assert::true(str_contains($error->getMessage(), 'not-observed or incomplete'));
                $directory->retainFailure($error);
            };
            $complete = static fn(string $journal) => PasswordHelperFixtureObservation::assertComplete($journal, $case);
            $controls = ['missing-healthy' => [1, [], true, $rejected], 'missing-disposed' => [0, [], true, $rejected],
                'prefix-healthy' => [1, ['fixture-entry'], true, $rejected], 'prefix-disposed' => [0, ['fixture-entry'], true, $rejected],
                'complete' => [0, PasswordHelperFixtureObservation::STAGES, false, $complete],
                'complete-extra' => [0, PasswordHelperFixtureObservation::STAGES, false, $rejected]];
            foreach ($controls as $control => [$signals, $stages, $failed, $collect]) {
                $journal = $directory->path . '/' . $control . '.jsonl';
                $result = Subprocess::run(PasswordHelperEvidenceStart::componentCommand($shell, $control, $directory, $journal, $caseId), $directory, [],
                    new \Tablo\Tests\Support\CommandOptions(timeout: 20));
                Assert::same($result['exit_code'], 23);
                Assert::true(str_contains($result['stdout'], 'HELPER_OBSERVER_CONTROL=caller-continued'));
                $match = []; preg_match('/HELPER_OBSERVER_CONTROL_RESULT=(.+)/', $result['stdout'], $match);
                $metadata = \Tablo\JsonRecord::decode(FixtureValue::string(FixtureValue::at($match, 1)));
                Assert::same(FixtureValue::at($metadata, 'failedLatch'), $failed);
                Assert::same(FixtureValue::at($metadata, 'noHelperExecuted'), true);
                $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($result['files']['receipt']);
                Assert::same(FixtureValue::at($metadata, 'wrapperPid'), $receipt['pid']);
                Assert::same($receipt['closed'], true);
                Assert::same($receipt['root_exit_observed'], true);
                Assert::same(substr_count($result['stderr'], needle: 'HELPER_FIXTURE_OBSERVER_FAILURE=append-failed'), $signals);
                Assert::same($result['stderr'], str_repeat(string: 'HELPER_FIXTURE_OBSERVER_FAILURE=append-failed' . PHP_EOL, times: $signals));
                Assert::same(array_column(PasswordHelperFixtureObservation::records($journal), 'stage'), $stages);
                $collect($journal);
            }
        } catch (Throwable $error) { $directory?->retainFailure($error); throw $error; }
        finally { PasswordHelperFixtureObservation::cleanupDirectory($directory); }
    }

    #[Test]
    public function fixtureReadinessRequiresEntryAndKnownTerminationExit(): void
    {
        $shell = self::shell();
        $delayed = null; $absent = null; $child = null; $primary = null;
        try {
            $delayed = new TemporaryDirectory('tablo-helper-ready-delayed-');
            $caseId = 'case-' . bin2hex(random_bytes(12));
            $case = PasswordHelperFixtureObservation::caseMetadata($caseId,
                ['encoding' => 'default', 'scenario' => 'unicode', 'phase' => 'fixture-control']);
            $journal = $delayed->path . '/entry.jsonl';
            $argv = PasswordHelperEvidenceStart::componentCommand($shell, 'delayed-entry', $delayed, $journal, $caseId);
            $startedNs = FixtureValue::integer(hrtime(true));
            $child = new Subprocess($argv, $delayed);
            $child->send('');
            PasswordHelperEvidenceStart::awaitFixtureEntry($journal, $case, $startedNs);
            $error = self::rejection(static fn() => $child->finish(0.2));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'stage=timeout'));
            $receiptPath = FixtureValue::string(FixtureValue::at(\Tablo\Tests\Support\FixtureFiles::matches($delayed->path . '/command-*.json'), 0));
            $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($receiptPath);
            PasswordHelperEvidence::assertTerminated($receipt);
            Assert::true(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($receipt['streams']['stdout']['path'])), 'HELPER_OBSERVER_CONTROL=entry'));
            $ready = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($delayed->path . '/fixture-readiness.json')));
            Assert::same(FixtureValue::at($ready, 'accepted'), true);
            Assert::same(FixtureValue::at($ready, 'budget_seconds'), 20);
            Assert::true(FixtureValue::integer(FixtureValue::at($ready, 'elapsed_ns')) >= 9_000_000_000);
            Assert::same(FixtureValue::at(FixtureValue::at(PasswordHelperFixtureObservation::records($journal), 0), 'wrapperPid'), $receipt['pid']);
            self::assertClosureCopies($delayed, $receipt, $receiptPath);

            $absent = new TemporaryDirectory('tablo-helper-ready-absent-');
            $journal = $absent->path . '/entry.jsonl';
            $argv = PasswordHelperEvidenceStart::componentCommand($shell, 'no-entry', $absent, $journal, $caseId);
            $startedNs = FixtureValue::integer(hrtime(true));
            $child = new Subprocess($argv, $absent);
            $child->send('');
            $error = self::rejection(static fn() => PasswordHelperEvidenceStart::awaitFixtureEntry($journal, $case, $startedNs, timeout: 0.2));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'stage=fixture-readiness; state=not-observed'));
            $absent->retainFailure($error);
            $child->close($error);
            PasswordHelperEvidence::assertTerminated(\Tablo\Tests\Support\SubprocessReceipt::read(FixtureValue::string(
                FixtureValue::at(\Tablo\Tests\Support\FixtureFiles::matches($absent->path . '/command-*.json'), 0))));
            Assert::false(is_file($journal), 'No entry observed; execution of the component loop remains UNKNOWN');
            $ready = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($absent->path . '/fixture-readiness.json')));
            Assert::same(FixtureValue::at($ready, 'accepted'), false);
        } catch (Throwable $error) {
            $primary = $error; $delayed?->retainFailure($error); $absent?->retainFailure($error); throw $error;
        } finally {
            try { $child?->close($primary); }
            finally { PasswordHelperFixtureObservation::cleanupDirectory($delayed); PasswordHelperFixtureObservation::cleanupDirectory($absent); }
        }
    }

    /** @param callable(): mixed $call */
    private static function rejection(callable $call): Throwable
    {
        try { $call(); }
        catch (Throwable $error) { return $error; }
        Assert::fail('Required negative control did not throw');
    }

    /** @param array{pid: ?int, exit_code: ?int, closed: bool, root_exit_observed: bool, ...} $receipt */
    private static function assertClosureCopies(TemporaryDirectory $directory, array $receipt, string $receiptPath): void
    {
        $negative = $receipt;
        $negative['exit_code'] = null;
        PasswordHelperEvidenceFiles::record($directory->path . '/negative-null-exit.json',
            ['classification' => 'SYNTHETIC negative copy', 'source_receipt' => $receiptPath, 'receipt' => $negative]);
        Assert::instanceOf(self::rejection(static fn() => PasswordHelperEvidence::assertTerminated($negative)), RuntimeException::class);
        $negative = $receipt;
        $negative['exit_code'] = 0;
        PasswordHelperEvidenceFiles::record($directory->path . '/negative-zero-exit.json',
            ['classification' => 'SYNTHETIC negative copy', 'source_receipt' => $receiptPath, 'receipt' => $negative]);
        Assert::instanceOf(self::rejection(static fn() => PasswordHelperEvidence::assertTerminated($negative)), RuntimeException::class);
        $negative = $receipt;
        $negative['root_exit_observed'] = false;
        PasswordHelperEvidenceFiles::record($directory->path . '/negative-unknown-root-exit.json',
            ['classification' => 'SYNTHETIC negative copy', 'source_receipt' => $receiptPath, 'receipt' => $negative]);
        Assert::instanceOf(self::rejection(static fn() => PasswordHelperEvidence::assertTerminated($negative)), RuntimeException::class);
    }

    #[Test]
    public function fixtureAppendCollisionFailsCollectorAndPreservesActualHelperFailure(): void
    {
        $shell = self::shell();
        $directory = null;
        $evidence = null;
        try {
            $directory = new TemporaryDirectory('tablo-helper-fixture-collision-');
            $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::helper($shell, 'default', 'startFailure', 'primary'));
            file_put_contents($evidence->fixturePath, data: 'preserve original observer collision');
            $result = Subprocess::run(PasswordHelperCommand::forEvidence($evidence), $directory, [],
                new \Tablo\Tests\Support\CommandOptions(timeout: 20));
            (new ReflectionMethod(PasswordHelperTest::class, 'assertMetadata'))->invoke(null, $result, 1);
            Assert::true(\Tablo\Tests\Support\SubprocessReceipt::read($evidence->path . '/final.receipt.json')['closed']);
            Assert::same(substr_count($result['stderr'], needle: 'HELPER_FIXTURE_OBSERVER_FAILURE=append-failed'), 1);
            Assert::same(PasswordHelperFixtureObservation::status($evidence, $result['stderr'])['fixture']['state'], 'append-failed');
            $error = self::rejection($evidence->assertCollected(...));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'fixture observer collector failed'));
            $directory->retainFailure($error); $evidence->failure($error);
            Assert::same(\Tablo\RequiredFile::bytes(file_get_contents($evidence->fixturePath)), 'preserve original observer collision');
            Assert::true(in_array('interrupted', array_column(PasswordHelperFixtureObservation::records($evidence->nativePath), 'stage'), strict: true));
        } catch (Throwable $error) { $directory?->retainFailure($error); $evidence?->failure($error); throw $error; }
        finally { PasswordHelperFixtureObservation::cleanupDirectory($directory); }
    }

    #[Test]
    public function normalFixtureOrdersNativeEventsWithOriginalCredentialEncodingRepeatAndPrivacyAssertions(): void
    {
        $shell = self::shell();
        $sink = null;
        try {
            $sink = new TemporaryDirectory('tablo-helper-fixture-normal-');
            // The original real case retains all credential, encoding, repeat and privacy assertions and 20s limits.
            (new ReflectionMethod(PasswordHelperTest::class, 'runCase'))->invoke(null, $shell, 'default', 'unicode', 0, $sink->path);
            $cases = \Tablo\Tests\Support\FixtureFiles::matches($sink->path . '/case-*');
            Assert::same(count($cases), 2);
            $phases = [];
            foreach ($cases as $path) { $phases[] = self::assertOrderedCase($path); }
            sort($phases);
            Assert::same($phases, ['primary', 'repeat']);
        } catch (Throwable $error) { $sink?->retainFailure($error); throw $error; }
        finally { PasswordHelperFixtureObservation::cleanupDirectory($sink); }
    }

    private static function assertOrderedCase(string $path): string
    {
        $fixture = PasswordHelperFixtureObservation::records($path . '/fixture-observation.jsonl');
        $native = PasswordHelperFixtureObservation::records($path . '/native-observation.jsonl');
        Assert::same(array_column($fixture, 'stage'), PasswordHelperFixtureObservation::STAGES);
        $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($path . '/final.receipt.json');
        $first = FixtureValue::at($fixture, 0);
        Assert::same(FixtureValue::at($first, 'encoding'), null, 'entry precedes original encoding capture');
        $case = \Tablo\JsonRecord::checked(FixtureValue::at($first, 'case'));
        Assert::same(FixtureValue::at($case, 'id'), basename($path));
        Assert::same(FixtureValue::at($case, 'control'), 'none');
        $caseKeys = array_keys($case); sort($caseKeys);
        Assert::same($caseKeys, ['control', 'encoding', 'id', 'mode', 'phase', 'scenario']);
        $stdout = \Tablo\RequiredFile::bytes(file_get_contents($path . '/final.stdout'));
        $match = []; preg_match('/HELPER_METADATA=(.+)/', $stdout, $match);
        $metadata = \Tablo\JsonRecord::checked(json_decode($match[1] ?? '', associative: true, flags: JSON_THROW_ON_ERROR));
        foreach ($fixture as $index => $record) {
            Assert::same(FixtureValue::at($record, 'sequence'), $index + 1);
            Assert::same(FixtureValue::at($record, 'wrapperPid'), $receipt['pid']);
            self::assertFixtureRecord($record, $metadata, $index);
            if ($index > 0) {
                Assert::true(FixtureValue::integer(FixtureValue::at($record, 'monotonicTicks')) >=
                    FixtureValue::integer(FixtureValue::at(FixtureValue::at($fixture, $index - 1), 'monotonicTicks')));
            }
        }
        Assert::same(FixtureValue::at(FixtureValue::at($native, 0), 'stage'), 'before-protected-prompts');
        $before = FixtureValue::integer(FixtureValue::at(FixtureValue::at($fixture, 7), 'monotonicTicks'));
        $after = FixtureValue::integer(FixtureValue::at(FixtureValue::at($fixture, 8), 'monotonicTicks'));
        foreach (array_slice($native, offset: 0, length: -1) as $record) {
            Assert::same(FixtureValue::at($record, 'wrapperPid'), $receipt['pid']);
            $ticks = FixtureValue::integer(FixtureValue::at($record, 'monotonicTicks'));
            Assert::true($ticks >= $before && $ticks <= $after, 'native helper events inside fixture call');
        }
        Assert::same(FixtureValue::at(FixtureValue::at($native, count($native) - 1), 'stage'), 'fixture-preexit');
        $synthetic = explode("\n", \Tablo\RequiredFile::bytes(file_get_contents($path . '/expected-input.bin')))[0];
        Assert::true($synthetic !== '');
        Assert::false(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($path . '/fixture-observation.jsonl')), $synthetic));
        return FixtureValue::string(FixtureValue::at($case, 'phase'));
    }

    /** @param array<array-key, mixed> $record
     * @param array<array-key, mixed> $metadata */
    private static function assertFixtureRecord(array $record, array $metadata, int $index): void
    {
        Assert::same(FixtureValue::at($record, 'scope'), 'fixture');
        Assert::true(FixtureValue::string(FixtureValue::at($record, 'powerShell')) !== '');
        Assert::true(FixtureValue::string(FixtureValue::at($record, 'dotNet')) !== '');
        $keys = array_keys($record); sort($keys);
        Assert::same($keys, ['case', 'dotNet', 'encoding', 'hostName', 'monotonicFrequency', 'monotonicTicks',
            'nativeCallback', 'parameterPresence', 'powerShell', 'scope', 'sequence', 'stage', 'utc', 'wrapperPid']);
        $presence = \Tablo\JsonRecord::checked(FixtureValue::at($record, 'parameterPresence'));
        ksort($presence);
        Assert::same($presence, ['CaseId' => true, 'CasePhase' => true, 'FixtureControl' => true,
            'FixtureObservationPath' => true, 'Helper' => true, 'HostEncoding' => true, 'Mode' => false,
            'ObservationPath' => true, 'Php' => true, 'Scenario' => true]);
        if ($index >= 2) {
            $encoding = \Tablo\JsonRecord::checked(FixtureValue::at($record, 'encoding'));
            ksort($encoding);
            Assert::same($encoding, ['codePage' => FixtureValue::at($metadata, 'beforeCodePage'),
                'preambleBytes' => FixtureValue::at($metadata, 'beforePreambleBytes')], 'reuse original encoding capture');
        }
    }
}
