<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\Subprocess;
use Testo\Assert;
use Testo\Test;

final class CheckCommandStderrTest extends CheckCommandFixtureCase
{


    #[Test]
    public function failedStderrPreservesHelpInvalidArgumentsAndBootstrapOutcomes(): void
    {
        try {
            $this->start();
            foreach ([\Tablo\Tests\Support\StderrCapture::File, \Tablo\Tests\Support\StderrCapture::ClosedReader] as $capture) {
                foreach ([['--json', '--help'], ['--json', '--unknown'], ['--json']] as $arguments) {
                    $help = in_array('--help', $arguments, strict: true);
                    $bootstrap = count($arguments) === 1;
                    $result = $this->stderrBoundary($arguments, $capture,
                        ['TABLO_DB' => $bootstrap ? $this->directory->path : $this->directory->path . '/absent/no.sqlite']);
                    $document = $this->document($result, $help ? 'success' : 'fatal', $help ? 0 : 1);
                    Assert::same($document['run']['reason'], $help ? 'help' : (match (true) { ($bootstrap) => 'storage-failed', default => 'invalid-arguments' }));
                    Assert::same($document['sites'], []);
                    Assert::false(is_dir($this->directory->path . '/absent'));
                }
            }
            Assert::same($this->requests(), []);
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }



    #[Test]
    public function failedStderrPreservesCompletedRecordsAndActualLateStorageFailure(): void
    {
        foreach ([\Tablo\Tests\Support\StderrCapture::File, \Tablo\Tests\Support\StderrCapture::ClosedReader] as $capture) {
            try {
                $this->start(); $first = $this->row(); $second = $this->row(); $third = $this->row();
                $this->db->exec("CREATE TRIGGER late_store_failure BEFORE UPDATE OF checked_at ON sites WHEN OLD.id = "
                    . $second . " BEGIN SELECT RAISE(ABORT, '" . $this->marker . "'); END");
                $document = $this->document($this->stderrBoundary(['--json'], $capture), 'fatal', 1);
                Assert::same($document['run']['reason'], 'storage-failed');
                Assert::same(array_column($document['sites'], 'id'), [$first, $second, $third]);
                Assert::same($document['sites'][0]['reason'], 'ok');
                Assert::same($document['sites'][0]['persistence']['accepted'], true);
                Assert::same($document['sites'][0]['persistence']['accepted_checked_at'], $this->sites->find($first)['checked_at']);
                Assert::same($document['sites'][1]['reason'], 'storage-failed');
                Assert::same($document['sites'][1]['persistence']['status'], 'unknown');
                Assert::same($document['sites'][1]['persistence']['accepted'], null);
                Assert::same($document['sites'][2]['status'], 'skipped');
                Assert::same($document['sites'][2]['reason'], 'run-aborted');
                Assert::same($this->sites->find($second)['checked_at'], null);
                Assert::same($this->sites->find($third)['checked_at'], null);
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $this->close(); }
        }
    }

    private function stderrBoundary(array $arguments, \Tablo\Tests\Support\StderrCapture $capture, array $changes = []): array
    {
        $command = $this->command($arguments);
        $command[2] = 'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/check-stderr-barrier.php';
        $result = Subprocess::run(command: $command, directory: $this->directory, environment: $this->environment($changes), options: new \Tablo\Tests\Support\CommandOptions(input: "go\n", stderr: $capture));
        $this->record($result, 'stderr-boundary:' . implode(' ', $arguments) . ':' . ($capture === \Tablo\Tests\Support\StderrCapture::ClosedReader ? 'closed' : 'working'));
        $receipt = json_decode(file_get_contents($result['files']['receipt']), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
        Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
        Assert::true(is_int($receipt['pid']) && $receipt['pid'] > 0);
        switch (true) {
case ($capture === \Tablo\Tests\Support\StderrCapture::ClosedReader):

            Assert::same($receipt['streams']['stderr']['bytes'], null);
            Assert::same($receipt['streams']['stderr']['errno'], null);
            Assert::true(in_array('stderr-reader-closed-before-input', array_column($receipt['events'], 'stage'), strict: true));

    break;
default:
 Assert::true($result['stderr'] !== '', 'working sink captures the fixed diagnostic');
    break;
}
        Assert::false(str_contains($result['stdout'] . $result['stderr'], $this->marker));
        return $result;
    }
}
