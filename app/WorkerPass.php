<?php
declare(strict_types=1);

namespace Tablo;

final class WorkerPass
{
    private readonly SiteRepository $sites;
    private readonly WorkerStateRepository $state;
    private readonly NotificationDelivery $notifications;
    private readonly \Closure $connection;
    private readonly \Closure $output;
    private readonly TokenVault $vault;
    public function __construct(private readonly WorkerRuntime $runtime, private readonly HttpClient $http, private readonly \Closure $stop)
    {
        $this->sites=$runtime->sites; $this->state=$runtime->state; $this->notifications=$runtime->notifications;
        $this->connection=$runtime->connection; $this->output=$runtime->output; $this->vault=$runtime->vault;
    }
    public function runPass(): void
    {
        $this->sites->assertWorkerKeyAvailable();
        $connection = ($this->connection)();
        $priorities = (new WorkerSitePriority($this->sites,$this->state))->ordered($connection);
        $check = new WorkerSiteCheck($this->sites,$this->state,$this->http,$this->vault,$this->output);
        foreach ($priorities as [$id]) {
            if (($this->stop)()) { break; }
            $check->check($id,$connection);
        }
        $delivery=$this->notifications->runOne($this->stop);
        if (!in_array($delivery['code'],['off','idle'],strict: true)) { ($this->output)('Worker notification: '.$delivery['code'].'.'); }
        }
}
