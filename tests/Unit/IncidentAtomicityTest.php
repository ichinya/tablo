<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\MigrationPdo;
use Testo\Assert;
use Testo\Test;

final class IncidentAtomicityTest
{
    #[Test]
    public function openingClosingCheckpointCreditAndDeferredCommitRollBackAllSixOwners(): void
    {
        foreach (['open', 'close', 'checkpoint-insert', 'checkpoint-update', 'credit', 'commit', 'interrupt'] as $failure) {
            $db = Database::connect(':memory:');
            $sites = new SiteRepository($db);
            $id = $sites->save(F::site());
            if (in_array($failure, ['close', 'checkpoint-update', 'interrupt'], true)) { F::accept($sites, $id, 0, '2026-01-01T00:00:00Z'); }
            if ($failure === 'interrupt') { $sites->save(F::site(), $id); }
            $trigger = match ($failure) {
                'open' => 'BEFORE INSERT ON incidents',
                'close', 'interrupt' => 'BEFORE UPDATE ON incidents',
                'checkpoint-insert' => 'BEFORE INSERT ON incident_checkpoints',
                'checkpoint-update' => 'BEFORE UPDATE ON incident_checkpoints',
                'credit' => 'BEFORE INSERT ON worker_progress',
                default => 'AFTER INSERT ON incident_checkpoints',
            };
            if ($failure === 'commit') {
                $db->exec('CREATE TABLE deferred_control(parent INTEGER REFERENCES sites(id) DEFERRABLE INITIALLY DEFERRED)');
                $body = 'INSERT INTO deferred_control VALUES(-1);';
            } else { $body = "SELECT RAISE(ABORT, 'incident-first-refusal');"; }
            $db->exec('CREATE TRIGGER refusal ' . $trigger . ' BEGIN ' . $body . ' END');
            $before = F::rows($db);
            $state = ['online' => $failure === 'close' ? 1 : 0, 'checked_at' => '2026-01-01T00:01:00Z',
                'worker_service' => ['latest_release' => true]];
            $error = F::error(fn () => (new WorkerStateRepository($db))->settle($sites->find($id), $state));
            Assert::instanceOf($error, \PDOException::class, $failure);
            Assert::same(F::rows($db), $before, $failure);
            $db->exec('DROP TRIGGER refusal');
            Assert::true((new WorkerStateRepository($db))->settle($sites->find($id), $state), 'connection reused ' . $failure);
        }
    }

    #[Test]
    public function failedBeginCallerTransactionImplicitRollbackAndOriginalErrorRemainOwned(): void
    {
        $db = new MigrationPdo(':memory:');
        Database::migrate($db);
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        $state = ['online' => 0, 'checked_at' => '2026-01-01T00:00:00Z'];
        $db->beginTransaction();
        $db->exec('UPDATE installation_settings SET check_interval_minutes=13');
        Assert::instanceOf(F::error(fn () => $sites->storeCheck($sites->find($id), $state)), \PDOException::class);
        Assert::same((int) $db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 13);
        $db->rollBack();
        $db->exec('CREATE TRIGGER refusal BEFORE INSERT ON incidents BEGIN SELECT RAISE(ROLLBACK, \'original-incident-error\'); END');
        $before = F::rows($db);
        $db->failRollback = true;
        $error = F::error(fn () => $sites->storeCheck($sites->find($id), $state));
        Assert::true(str_contains($error->getMessage(), 'original-incident-error'));
        Assert::same(F::rows($db), $before);
        $db->failRollback = false;
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('ROLLBACK');
        $db->exec('DROP TRIGGER refusal');
        Assert::true($sites->storeCheck($sites->find($id), $state));
    }
}
