<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;

final class HistoryProjectionAssertions
{
    public function __construct(private readonly \PDO $db) {}

    public function settled(int $id, string $action, array $before): void
    {
        [$checkpoint, $incident, $notification] = $before;
        $accepted = $action === 'rename';
                $statement = $this->db->prepare('SELECT COUNT(*) FROM check_history WHERE site_id=?');
                $statement->execute([$id]);
                Assert::same((int) $statement->fetchColumn(), $action === 'delete' ? 0 : (match (true) { ($accepted) => 3, default => 1 }), $action);
                $statement->closeCursor();
                Assert::same($this->db->query('SELECT * FROM incidents WHERE site_id=' . $id)->fetch(), $action === 'delete' ? false : $incident, $action);
                if (!$accepted) {
                    Assert::same($this->db->query('SELECT * FROM incident_checkpoints WHERE site_id=' . $id)->fetch(), $action === 'delete' ? false : $checkpoint, $action);
                    Assert::same($this->db->query('SELECT * FROM notification_checkpoints WHERE site_id=' . $id)->fetch(), $action === 'delete' ? false : $notification, $action);
                }
                Assert::same($this->db->query('SELECT * FROM notification_slots WHERE site_id=' . $id)->fetchAll(),[], $action);
                if (!$accepted) { Assert::same((int) $this->db->query('SELECT COUNT(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0); }

    }
}
