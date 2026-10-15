<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use RuntimeException;
use Throwable;
use WeakMap;

/** Only an explicit synthetic case activates subprocess observation. */
final class PasswordHelperEvidence
{
    /** @var WeakMap<TemporaryDirectory, self>|null */
    private static ?WeakMap $active = null;
    public readonly string $path;
    public readonly string $nativePath;
    private int $sequence = 0;
    /** @var list<string> */
    private array $collectorFailures = [];
    /** @var array{pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int, cached?: bool}|null */
    public ?array $lastStatus = null;

    public function __construct(private readonly TemporaryDirectory $directory, public readonly PasswordHelperCase $case, ?string $sink = null)
    {
        $this->path = PasswordHelperEvidenceStart::create($directory, $case, $sink);
        $this->nativePath = $this->path . '/native-observation.jsonl';
        self::$active ??= new WeakMap();
        self::$active[$directory] = $this;
    }

    public static function active(TemporaryDirectory $directory): ?self
    { return self::$active[$directory] ?? null; }

    /** Collector errors never interrupt the child's existing settlement path. */
    public function observe(SubprocessState $state): void
    {
        try { PasswordHelperCapture::snapshot($this, $state, ++$this->sequence); }
        catch (Throwable $error) { $this->collectorFailure($error); }
    }

    public function closed(SubprocessState $state): void
    {
        try { PasswordHelperCapture::finalReceipt($this, $state); }
        catch (Throwable $error) { $this->collectorFailure($error); }
    }

    public function termination(SubprocessState $state, int $signal, bool $returned): void
    { $state->event('termination-request-returned', ['signal' => $signal, 'returned' => $returned]); }

    public function afterTermination(SubprocessState $state): void
    { $state->event('after-termination-wait'); }

    private function collectorFailure(Throwable $error): void
    {
        $this->collectorFailures[] = $error::class;
        $this->directory->retainFailure($error);
        fwrite(STDERR, 'HELPER_COLLECTOR_FAILURE=' . $error::class . "\n");
    }

    /** No arbitrary exception messages or database content leave the failed fixture. */
    public function failure(Throwable $error): void
    {
        try { PasswordHelperEvidenceFiles::record($this->path . '/assertion-failure-' . bin2hex(random_bytes(8)) . '.json',
            ['utc' => gmdate('c'), 'type' => $error::class, 'fixture_retained' => true, 'case' => $this->case->identity]); }
        catch (Throwable $collector) { $this->collectorFailure($collector); }
    }

    public function assertCollected(): void
    {
        if ($this->collectorFailures !== []) { throw new RuntimeException('Synthetic helper evidence collector failed; original child outcome retained'); }
        $stderr = $this->path . '/final.stderr';
        if (is_file($stderr) && str_contains(\Tablo\RequiredFile::bytes(file_get_contents($stderr)), 'HELPER_OBSERVER_FAILURE=')) {
            throw new RuntimeException('Synthetic native helper observer failed; original child outcome retained');
        }
    }
}
