<?php
declare(strict_types=1);

namespace Tablo;

use Closure;
use PDO;

final class PeriodicWorker
{
    private readonly SiteRepository $sites;
    private readonly SettingsRepository $settings;
    private readonly WorkerStateRepository $state;
    private readonly Closure $connection;
    private readonly Closure $clock;
    private readonly Closure $sleep;
    private readonly Closure $output;
    private bool $stop = false;

    public function __construct(
        private readonly PDO $db,
        private readonly HttpClient $http,
        #[\SensitiveParameter] ?Closure $connection = null,
        ?Closure $clock = null,
        ?Closure $sleep = null,
        ?Closure $output = null,
    ) {
        $this->sites = new SiteRepository($db);
        $this->settings = new SettingsRepository($db);
        $this->state = new WorkerStateRepository($db);
        $sites = $this->sites;
        $this->connection = $connection ?? static fn (): GitHubConnection => new GitHubConnection($sites, $http);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static fn (float $seconds): mixed => usleep((int) ceil($seconds * 1e6));
        $this->output = $output ?? static fn (string $message): int|false => fwrite(STDOUT, $message . PHP_EOL);
    }

    public function stop(): void { $this->stop = true; }

    public function run(): int
    {
        $lock = new WorkerLock();
        if (!$lock->acquire($this->db)) { ($this->output)('Worker already running.'); return 2; }
        $generation = null;
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
            return 0;
        } finally {
            try { if ($generation !== null) { $this->state->finish($generation); } }
            finally { $lock->close(); }
        }
    }

    private function stopping(string $generation): bool
    {
        return $this->stop || $this->state->stopping($generation);
    }

    // One finite serial pass; a connection owns the aggregate policy/memo for this pass only.
    public function runPass(?string $generation = null): void
    {
        $connection = ($this->connection)();
        $priorities = [];
        foreach ($this->sites->enabledIds() as $position => $id) {
            $site = $this->sites->find($id);
            if ($site === null || !$site['enabled']) { continue; }
            try {
                $eligible = $connection->provider($site)->eligibleResources();
                $turns = $this->state->turns($id, $site['config_revision']);
                $available = array_filter($turns, static fn (string $field): bool =>
                    $eligible[in_array($field, ['open_issues', 'open_prs'], true) ? 'search' : 'core'], ARRAY_FILTER_USE_KEY);
                $priority = $available === [] ? PHP_INT_MAX : min($available);
            } catch (\Throwable) { $priority = PHP_INT_MAX; }
            $priorities[] = [$id, $priority, $position];
        }
        usort($priorities, static fn (array $left, array $right): int => [$left[1], $left[2]] <=> [$right[1], $right[2]]);
        foreach ($priorities as [$id]) {
            if ($this->stop || ($generation !== null && $this->state->stopping($generation))) { break; }
            try {
                $site = $this->sites->find($id);
                if ($site === null || !$site['enabled']) { continue; }
                try {
                    $provider = $connection->provider($site);
                    $order = $this->fieldOrder($site, $provider);
                } catch (\Throwable) {
                    $provider = new UnavailableRepositoryProvider();
                    $order = WorkerStateRepository::FIELDS;
                    ($this->output)('Worker credentials unavailable.');
                }
                $result = (new SiteChecker($this->http, $provider))->check($site, $order);
                // Admission credit survives a result-store failure; revision guards still reject edits.
                $this->state->credit($id, $site['config_revision'], $result['worker_service']);
                $stored = $this->sites->storeCheck($site, $result);
                ($this->output)($stored ? 'Worker site checked.' : 'Worker site changed.');
            } catch (\Throwable) { ($this->output)('Worker site failed.'); }
        }
    }

    private function fieldOrder(#[\SensitiveParameter] array $site, GitHubProvider $provider): array
    {
        $turns = $this->state->turns($site['id'], $site['config_revision']);
        $eligible = $provider->eligibleResources();
        $order = WorkerStateRepository::FIELDS;
        $key = static fn (string $field): array => [
            (int) !$eligible[in_array($field, ['open_issues', 'open_prs'], true) ? 'search' : 'core'],
            $turns[$field], array_search($field, WorkerStateRepository::FIELDS, true)];
        usort($order, static fn (string $left, string $right): int => $key($left) <=> $key($right));
        return $order;
    }
}
