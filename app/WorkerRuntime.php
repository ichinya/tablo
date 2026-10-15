<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerRuntime
{
    public readonly SiteRepository $sites;
    public readonly SettingsRepository $settings;
    public readonly WorkerStateRepository $state;
    public readonly NotificationDelivery $notifications;
    public readonly \Closure $connection;
    public readonly \Closure $clock;
    public readonly \Closure $sleep;
    public readonly \Closure $output;
    public readonly TokenVault $vault;
    public function __construct(\PDO $db,HttpClient $http,?WorkerHooks $hooks,#[\SensitiveParameter] ?TokenVault $vault)
    {
        $this->vault = $vault ?? TokenVault::forDatabase($db);
        $this->sites = new SiteRepository($db, $this->vault);
        $this->settings = new SettingsRepository($db);
        $this->state = new WorkerStateRepository($db, $this->sites);
        $this->notifications = new NotificationDelivery($db,$this->vault);
        $sites = $this->sites;
        $this->connection = $hooks->connection ?? static fn (): GitHubConnection => new GitHubConnection($sites, $http, worker: true);
        $this->clock = $hooks->clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $hooks->sleep ?? static fn (float $seconds): mixed => usleep((int) ceil($seconds * 1e6));
        $this->output = $hooks->output ?? static fn (string $message): int|false => fwrite(STDOUT, $message . PHP_EOL);
        }
}
