<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use ReflectionMethod;
use RuntimeException;
use Throwable;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\PasswordHelperCase;
use Tablo\Tests\Support\PasswordHelperEvidence;
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
            $child = new Subprocess(PasswordHelperCommand::forEvidence($evidence), $directory);
            $child->send('');
            $child->awaitOutput('HELPER_FIXTURE_CONTROL=pre-helper-stall', timeout: 8);
            try { $child->finish(0.2); Assert::fail('Deliberately live pre-helper fixture must time out'); }
            catch (RuntimeException $error) { Assert::true(str_contains($error->getMessage(), 'stage=timeout')); }
            $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($evidence->path . '/final.receipt.json');
            Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
            Assert::true($receipt['pid'] !== null && $receipt['exit_code'] !== 0);
            $entry = PasswordHelperFixtureObservation::records($evidence->fixturePath);
            Assert::same(array_column($entry, 'stage'), ['fixture-entry']);
            Assert::same(FixtureValue::at(FixtureValue::at($entry, 0), 'wrapperPid'), $receipt['pid']);
            $status = PasswordHelperFixtureObservation::status($evidence, '');
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
            try { $evidence->assertCollected(); Assert::fail('Observer append refusal must fail collection'); }
            catch (RuntimeException $error) {
                Assert::true(str_contains($error->getMessage(), 'fixture observer collector failed'));
                $directory->retainFailure($error); $evidence->failure($error);
            }
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
