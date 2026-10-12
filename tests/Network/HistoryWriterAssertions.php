<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteRepository;
use Testo\Assert;

final class HistoryWriterAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites) {}

    public function blocked(int $id, array $snapshot, array $state): void
    {
            $error = null;
            try { $this->sites->storeCheck($snapshot, $state); } catch (\PDOException $caught) { $error = $caught->errorInfo[1]; }
            Assert::same($error, 5);
            Assert::same((int) $this->db->query('SELECT COUNT(*) FROM check_history')->fetchColumn(), 0);
            Assert::same($this->sites->find($id)['checked_at'], null);
            unset($caught);

    }
}
