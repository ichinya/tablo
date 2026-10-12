<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\SiteRepository;
use Testo\Assert;

/** @phpstan-import-type Config from \Tablo\SiteCheckWriter */
final class HistoryWriterAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites) {}

    /** @param Config $snapshot */
    public function blocked(int $id, array $snapshot, array $state): void
    {
            $error = null;
            try { $this->sites->storeCheck($snapshot, $state); } catch (\PDOException $caught) { $error = \Tablo\Tests\Support\FixtureNative::sqliteCode($caught); }
            Assert::same($error, 5);
            Assert::same((int) \Tablo\SqlStatement::checked($this->db->query('SELECT COUNT(*) FROM check_history'))->fetchColumn(), 0);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($this->sites->find($id))['checked_at'], null);
            unset($caught);

    }
}
