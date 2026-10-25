<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Throwable;
use Tablo\Tests\Support\FixtureFiles;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\PasswordHelperEvidence;
use Tablo\Tests\Support\PasswordHelperEvidenceFiles;
use Tablo\Tests\Support\PasswordHelperEvidenceStart;
use Tablo\Tests\Support\PasswordHelperFixtureObservation;
use Tablo\Tests\Support\PasswordHelperFixtureReadiness;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\SubprocessReceipt;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

final class PasswordHelperFixtureReadinessTest
{
    private const MARKER = 'HELPER_OBSERVER_CONTROL=entry';
    private const CASES = [
        'entry-before-marker' => ['entry' => true, 'marker' => false, 'accepted' => true, 'final_marker' => true],
        'entry-no-marker' => ['entry' => true, 'marker' => false, 'accepted' => false, 'final_marker' => false],
        'marker-no-entry' => ['entry' => false, 'marker' => true, 'accepted' => false, 'final_marker' => true],
    ];

    #[Test]
    public function fixtureReadinessRequiresMarkerAndEntryWithinOriginalBudget(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { throw new SkipTest('Native Windows PowerShell fixture control required.'); }
        $shell = FixtureValue::string(getenv('SystemRoot')) . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        foreach (array_keys(self::CASES) as $control) { self::runCase($shell, $control); }
    }

    private static function runCase(string $shell, string $control): void
    {
        $directory = null; $child = null; $primary = null;
        try {
            $directory = new TemporaryDirectory('tablo-helper-ready-marker-');
            $caseId = 'case-' . bin2hex(random_bytes(12));
            $case = PasswordHelperFixtureObservation::caseMetadata($caseId,
                ['encoding' => 'default', 'scenario' => 'unicode', 'phase' => 'fixture-control']);
            if ($control === 'entry-before-marker') { self::assertBodyOnlyRefused($case); }
            $journal = $directory->path . '/entry.jsonl';
            $argv = PasswordHelperEvidenceStart::componentCommand($shell, $control, $directory, $journal, $caseId);
            $startedNs = FixtureValue::integer(hrtime(true));
            $child = new Subprocess($argv, $directory);
            $child->send('');
            self::assertPartial($directory, $case, $control, $startedNs, $child);
            self::settle($child, $directory, $case, $startedNs, $control);
            self::assertSettled($directory, $startedNs, $control);
        } catch (Throwable $error) { $primary = $error; $directory?->retainFailure($error); throw $error; }
        finally {
            try { $child?->close($primary); }
            finally { PasswordHelperFixtureObservation::cleanupDirectory($directory); }
        }
    }

    /** @param array<string, string> $case */
    private static function assertPartial(TemporaryDirectory $directory, array $case, string $control, int $startedNs, Subprocess $child): void
    {
        $expected = FixtureValue::at(self::CASES, $control);
        $partialMarker = $expected['entry'] ? 'HELPER_OBSERVER_CONTROL=journal-ready' : self::MARKER;
        $remaining = 20 - (FixtureValue::integer(hrtime(true)) - $startedNs) / 1_000_000_000;
        Assert::true($remaining > 0, 'Original prelaunch readiness budget must remain');
        $child->awaitOutput($partialMarker, $remaining);
        Assert::true(FixtureValue::integer(hrtime(true)) - $startedNs < 20_000_000_000);
        $journal = $directory->path . '/entry.jsonl';
        $stdout = FixtureFiles::first($directory->path . '/command-*.out');
        Assert::same(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($stdout)), self::MARKER), $expected['marker']);
        Assert::same(substr_count(\Tablo\RequiredFile::bytes(file_get_contents($stdout)),
            needle: PasswordHelperFixtureReadiness::BODY_MARKER), 1);
        Assert::same(is_file($journal), $expected['entry']);
        if ($expected['entry']) {
            PasswordHelperFixtureObservation::assertComplete($journal, $case);
            Assert::true(FixtureValue::integer(FixtureValue::at(FixtureValue::at(
                PasswordHelperFixtureObservation::records($journal), 0), 'wrapperPid')) > 0);
        }
        PasswordHelperEvidenceFiles::record($directory->path . '/partial-readiness.json', [
            'control' => $control, 'case' => $case, 'started_monotonic_ns' => $startedNs,
            'elapsed_ns' => FixtureValue::integer(hrtime(true)) - $startedNs,
            'entry_observed' => $expected['entry'], 'marker_observed' => $expected['marker'],
            'stdout_path' => $stdout, 'journal_path' => $journal]);
    }

    /** @param array<string, string> $case */
    private static function settle(Subprocess $child, TemporaryDirectory $directory, array $case, int $startedNs, string $control): void
    {
        $journal = $directory->path . '/entry.jsonl';
        $output = new PasswordHelperFixtureReadiness($directory, self::MARKER);
        if (FixtureValue::at(self::CASES, $control)['accepted']) {
            PasswordHelperEvidenceFiles::bytes($directory->path . '/release-marker', 'release owned marker');
            PasswordHelperEvidenceStart::awaitFixtureEntry($journal, $case, $startedNs, $output);
            $error = self::rejection(static fn() => $child->finish(0.2));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'stage=timeout'));
            return;
        }
        $error = self::rejection(static fn() => PasswordHelperEvidenceStart::awaitFixtureEntry($journal, $case, $startedNs, $output));
        Assert::instanceOf($error, RuntimeException::class);
        $state = $control === 'entry-no-marker' ? 'emitted; marker=not-observed' : 'not-observed; marker=emitted';
        Assert::true(str_contains($error->getMessage(), 'stage=fixture-readiness; state=' . $state));
        $directory->retainFailure($error);
        $child->close($error);
    }

    private static function assertSettled(TemporaryDirectory $directory, int $startedNs, string $control): void
    {
        $expected = FixtureValue::at(self::CASES, $control);
        $receipt = SubprocessReceipt::read(FixtureFiles::first($directory->path . '/command-*.json'));
        PasswordHelperEvidence::assertTerminated($receipt);
        $ready = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/fixture-readiness.json')));
        Assert::same(FixtureValue::at($ready, 'accepted'), $expected['accepted']);
        Assert::same(FixtureValue::at($ready, 'budget_seconds'), 20);
        Assert::same(FixtureValue::at($ready, 'started_monotonic_ns'), $startedNs);
        Assert::same(FixtureValue::at(\Tablo\JsonRecord::checked(FixtureValue::at($ready, 'stdout')), 'observed'), $expected['final_marker']);
        Assert::same(FixtureValue::at(\Tablo\JsonRecord::checked(FixtureValue::at($ready, 'stdout')), 'body_marker_observed'), true);
        $stdout = FixtureFiles::first($directory->path . '/command-*.out');
        Assert::same(str_contains(\Tablo\RequiredFile::bytes(file_get_contents($stdout)), self::MARKER), $expected['final_marker']);
        if ($expected['entry']) {
            Assert::same(FixtureValue::at(FixtureValue::at(PasswordHelperFixtureObservation::records(
                $directory->path . '/entry.jsonl'), 0), 'wrapperPid'), $receipt['pid']);
        }
    }

    /** @param array<string, string> $case */
    private static function assertBodyOnlyRefused(array $case): void
    {
        $directory = new TemporaryDirectory('tablo-helper-ready-body-only-');
        try {
            PasswordHelperEvidenceFiles::bytes($directory->path . '/command-body-only.out', PasswordHelperFixtureReadiness::BODY_MARKER . PHP_EOL);
            $journal = $directory->path . '/entry.jsonl';
            $startedNs = FixtureValue::integer(hrtime(true));
            $output = new PasswordHelperFixtureReadiness($directory, self::MARKER);
            $error = self::rejection(static fn() => PasswordHelperEvidenceStart::awaitFixtureEntry($journal, $case, $startedNs, $output, timeout: 0.2));
            Assert::instanceOf($error, RuntimeException::class);
            Assert::true(str_contains($error->getMessage(), 'state=not-observed; marker=not-observed; body-marker-observed=true'));
            Assert::false(is_file($journal));
            $ready = \Tablo\JsonRecord::decode(\Tablo\RequiredFile::bytes(file_get_contents($directory->path . '/fixture-readiness.json')));
            Assert::same(FixtureValue::at($ready, 'accepted'), false, 'Body entry alone cannot establish readiness');
            Assert::same(FixtureValue::at($ready, 'budget_seconds'), 0.2);
            $stdout = \Tablo\JsonRecord::checked(FixtureValue::at($ready, 'stdout'));
            Assert::same(FixtureValue::at($stdout, 'observed'), false);
            Assert::same(FixtureValue::at($stdout, 'body_marker_observed'), true);
        } catch (Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }

    /** @param callable(): mixed $call */
    private static function rejection(callable $call): Throwable
    {
        try { $call(); }
        catch (Throwable $error) { return $error; }
        Assert::fail('Required negative control did not throw');
    }
}
