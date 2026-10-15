<?php
declare(strict_types=1);
namespace Tablo\Tests\Network;


use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\WorkerProcess;
use Testo\Assert;

final class NotificationWorkerAssertions
{
    public function __construct(private readonly WorkerProcess $child, private readonly \PDO $db) {}

    public function completed(): void
    {
            $this->child->awaitOutput('Worker pass complete.'); $state=new WorkerStateRepository($this->db); Assert::true($state->requestStop($state->generation()));
            Assert::same($this->child->wait()['exit_code'],0); Assert::same((int)$this->db->query('SELECT online FROM sites WHERE id=2')->fetchColumn(),1);
            Assert::same($this->db->query("SELECT status FROM notification_slots WHERE site_id=1 AND event='recovery'")->fetchColumn(),'failed');
            Assert::true((int)$this->db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn()===2);

    }
}
