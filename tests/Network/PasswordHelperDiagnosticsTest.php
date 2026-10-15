<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use RuntimeException;
use Throwable;
use Tablo\Tests\Support\PasswordHelperCase;
use Tablo\Tests\Support\PasswordHelperEvidence;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

/** @phpstan-import-type Receipt from \Tablo\Tests\Support\SubprocessReceiptData */
final class PasswordHelperDiagnosticsTest
{
    /** @return Receipt */
    private static function receipt(string $path): array
    { return \Tablo\Tests\Support\SubprocessReceipt::read($path); }

    #[Test]
    public function helperSinkPreservesPortableFirstStoppedExitAndActualMissingExecutableOutcome(): void
    {
        foreach (['ok', 'nonzero', 'missing'] as $mode) {
            $directory = new TemporaryDirectory('tablo-helper-exit-control-');
            $case = $mode === 'missing' ? PasswordHelperCase::missing($directory) : PasswordHelperCase::diagnostic($mode);
            $evidence = new PasswordHelperEvidence($directory, $case);
            try {
                try {
                    $result = Subprocess::run($case->argv, $directory, []);
                    if ($mode === 'missing') { Assert::true($result['exit_code'] !== 0); }
                    if ($mode !== 'missing') { Assert::same($result['exit_code'], $mode === 'ok' ? 0 : 7); }
                    $receipt = self::receipt($evidence->path . '/final.receipt.json');
                    Assert::same($receipt['exit_code'], $result['exit_code']);
                    Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
                    $stopped = array_values(array_filter($receipt['events'], static fn(array $event): bool => $event['stage'] === 'root-exit'));
                    Assert::same(FixtureValue::at(FixtureValue::at($stopped, 0), 'exit_code'), $result['exit_code']);
                } catch (RuntimeException $error) {
                    if ($mode !== 'missing') { throw $error; }
                    Assert::true(str_contains($error->getMessage(), 'stage=spawn'));
                    $receipt = self::receipt($evidence->path . '/final.receipt.json');
                    Assert::same($receipt['pid'], null);
                    Assert::same($receipt['exit_code'], null);
                    Assert::false($receipt['root_exit_observed']);
                    Assert::true($receipt['closed']);
                }
                $evidence->assertCollected();
            } catch (Throwable $error) { $directory->retainFailure($error); $evidence->failure($error); throw $error; }
            finally { $directory->cleanup($directory->close(...)); }
        }
    }

    #[Test]
    public function helperCollectorFailureIsVisibleAndCannotEraseActualNonzeroExitOrClosure(): void
    {
        $directory = new TemporaryDirectory('tablo-helper-collector-control-');
        $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::diagnostic('nonzero'));
        // Exclusive-file collision is a real collector I/O failure before the child starts.
        file_put_contents($evidence->path . '/snapshot-1.stdout', data: 'preserve original collision');
        try {
            $result = Subprocess::run($evidence->case->argv, $directory, []);
            Assert::same($result['exit_code'], 7);
            Assert::true(self::receipt($result['files']['receipt'])['closed']);
            Assert::same(self::receipt($evidence->path . '/final.receipt.json')['exit_code'], 7);
            try { $evidence->assertCollected(); Assert::fail('Collector failure must be visible'); }
            catch (RuntimeException $error) { Assert::true(str_contains($error->getMessage(), 'collector failed')); }
            Assert::same(\Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/snapshot-1.stdout')), 'preserve original collision');
        } finally { $directory->close(); }
        Assert::true(is_dir($directory->path));
    }

    #[Test]
    public function helperPrivateFullStreamsKeepMarkerBeyond2048WhilePublishedSummaryRedactsIt(): void
    {
        $directory = new TemporaryDirectory('tablo-helper-privacy-control-');
        $secret = \Tablo\Tests\Support\FixtureCredentials::value(label: 'helper-privacy-marker');
        $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::diagnostic('private', secret: $secret));
        try {
            $result = Subprocess::run($evidence->case->argv, $directory, []);
            Assert::same($result['exit_code'], 7);
            $offset = strpos($result['stdout'], $secret);
            Assert::true($offset !== false && $offset > 2048);
            Assert::same(\Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stdout')), $result['stdout']);
            Assert::same(\Tablo\RequiredFile::digest($evidence->path . '/final.stdout'), hash('sha256', $result['stdout']));
            $summary = \Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/public.stdout.json'));
            Assert::false(str_contains($summary, $secret));
            Assert::true(str_contains($summary, '[synthetic input redacted]'));
            $evidence->assertCollected();
        } catch (Throwable $error) { $directory->retainFailure($error); $evidence->failure($error); throw $error; }
        finally { $directory->cleanup($directory->close(...)); }
    }

}
