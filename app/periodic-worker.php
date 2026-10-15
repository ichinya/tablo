<?php
declare(strict_types=1);

namespace Tablo;

use Closure;
use PDO;

// Pinned Lekalo requires lowercase physical source paths for this contracted owner.
// @mago-expect lint:file-name
final class PeriodicWorker
{
    private readonly SiteRepository $sites;
    private readonly SettingsRepository $settings;
    private readonly WorkerStateRepository $state;
    private readonly Closure $clock;
    private readonly Closure $sleep;
    private readonly Closure $output;
    private bool $stop = false;
    private readonly WorkerRuntime $runtime;
    public function __construct(private readonly PDO $db,private readonly HttpClient $http,?WorkerHooks $hooks=null,#[\SensitiveParameter] ?TokenVault $vault=null)
    {
        $this->runtime = new WorkerRuntime($db,$http,$hooks,$vault);
        $this->sites=$this->runtime->sites; $this->state=$this->runtime->state; $this->settings=$this->runtime->settings;
        $this->clock=$this->runtime->clock; $this->sleep=$this->runtime->sleep; $this->output=$this->runtime->output;
    }



    public function stop(): void { $this->stop = true; }

    public function run(): int
    {
        $this->sites->assertWorkerKeyAvailable(); // Refuse before lock/control initialization.
        $lock = new WorkerLock();
        if (!$lock->acquire($this->db)) { ($this->output)('Worker already running.'); return 2; }
        $generation = null;
        $failure = null;
        try {
            $this->settings->get(); // Reject invalid committed configuration before starting work.
            $generation = $this->state->begin();
            ($this->output)('Worker started.');
            while (!$this->stopping($generation)) {
                $this->runPass($generation);
                $seconds = $this->settings->get() * 60;
                $completed = ($this->clock)();
                ($this->output)('Worker pass complete.');
                while (!$this->stopping($generation)) {
                    $remaining = $seconds - (($this->clock)() - $completed);
                    if ($remaining <= 0) { break; }
                    ($this->sleep)(min(0.1, $remaining));
                }
            }
            ($this->output)('Worker stopped.');
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $failure = (new WorkerRunCleanup($this->state))->finishRun($generation, $lock, $failure);
        }
        if ($failure !== null) { throw $failure; }
        return 0;
    }



    private function stopping(string $generation): bool
    {
        return $this->stop || $this->state->stopping($generation);
    }

    // One finite serial pass; a connection owns the aggregate policy/memo for this pass only.



    public function runPass(?string $generation=null): void
    {
        (new WorkerPass($this->runtime,$this->http,fn (): bool => $this->stop || ($generation !== null && $this->state->stopping($generation))))->runPass();
    }
}
