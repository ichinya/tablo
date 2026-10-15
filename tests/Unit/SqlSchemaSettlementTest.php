<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use DateTimeImmutable;
use Tablo\Database;
use Tablo\HistoryPruner;
use Tablo\SiteInput;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class SqlSchemaSettlementTest extends SqlSchemaFixtureCase
{
    #[Test]
    public function realNamedAttributeProjectsTheAcceptedResultAfterHistoryPruning(): void
    {
        $directory = new TemporaryDirectory('tablo-sql-settlement-');
        try {
            $db = self::database('current8');
            self::redeclare($db, 'id INTEGER CONSTRAINT "autoincrement" PRIMARY KEY AUTOINCREMENT');
            Database::migrate($db);
            $sites = new SiteRepository($db, new TokenVault($directory->path . '/uninitialized.key'), static fn (): int => 1000);
            $id = $sites->save(array_replace(SiteInput::defaults(), ['name' => 'SQL projection', 'url' => 'https://example.test/', 'repository' => 'fixture/public']));
            Assert::true($sites->settleWorkerCheck(\Tablo\Tests\Support\FixtureValue::row($sites->find($id)), ['online' => 1, 'checked_at' => '2026-01-01T00:00:00Z',
                'worker_service' => ['latest_release' => true]]));
            Assert::same((new HistoryPruner($db))->prune(1, null, new DateTimeImmutable('2026-01-10T00:00:00Z')),
                ['deleted' => 1, 'capped' => false]);
            Assert::true($sites->settleWorkerCheck(\Tablo\Tests\Support\FixtureValue::row($sites->find($id)), ['online' => 0, 'health_error_code' => 'http', 'health_http_status' => 503,
                'checked_at' => '2026-01-11T00:00:00Z', 'worker_service' => ['latest_release' => true]]));
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT id FROM check_history'))->fetchColumn(), 2);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM incidents'))->fetchColumn(), 1);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT last_history_id FROM incident_checkpoints'))->fetchColumn(), 2);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT last_history_id FROM notification_checkpoints'))->fetchColumn(), 2);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM worker_progress'))->fetchColumn(), 1);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM worker_runtime'))->fetchColumn(), 1);
        } finally { $directory->close(); }
    }
}
