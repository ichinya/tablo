<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerRuntime
{
    public readonly SiteRepository $sites;
    public readonly SettingsRepository $settings;
    public readonly WorkerStateRepository $state;
    public readonly NotificationDelivery $notifications;
    public readonly SelfUpdateCheck $selfUpdate;
    /** @var \Closure(): GitHubConnection */
    public readonly \Closure $connection;
    /** @var \Closure(): (int|float) */
    public readonly \Closure $clock;
    /** @var \Closure(float): void */
    public readonly \Closure $sleep;
    /** @var \Closure(string): void */
    public readonly \Closure $output;
    public readonly TokenVault $vault;
    public function __construct(\PDO $db,HttpClient $http,?WorkerHooks $hooks,#[\SensitiveParameter] ?TokenVault $vault)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
        $this->sites = new SiteRepository($db, $this->vault);
        $this->settings = new SettingsRepository($db);
        $this->state = new WorkerStateRepository($db, $this->sites);
        $this->notifications = new NotificationDelivery($db,$this->vault);
        $this->selfUpdate = new SelfUpdateCheck($db, $http, $hooks?->epoch);
        $sites = $this->sites;
        $this->connection = $hooks->connection ?? static fn (): GitHubConnection => new GitHubConnection($sites, $http, worker: true);
        $this->clock = $hooks->clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $hooks->sleep ?? static function (float $seconds): void {
            $microseconds = (int) ceil($seconds * 1e6);
            if ($microseconds < 0) { throw new \ValueError('usleep(): Argument #1 ($microseconds) must be greater than or equal to 0'); }
            usleep($microseconds);
        };
        $this->output = $hooks->output ?? static fn (string $message): int|false => fwrite(STDOUT, $message . PHP_EOL);
        }
}
