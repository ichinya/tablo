<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDOException;
use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerConnectionOwnershipTest
{
    #[Test]
    public function differentConnectionRefusesBeforeKeyEffectsAndPreservesBothCallerTransactions(): void
    {
        $directory = new TemporaryDirectory('tablo-settlement-connection-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $a = $b = $sitesA = $sitesB = $state = $vault = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $key = $directory->path . '/key';
            $original = random_bytes(32);
            file_put_contents($key, $original);
            $vault = new TokenVault($key, true);
            $a = Database::connect(':memory:', $vault);
            $b = Database::connect(':memory:', $vault);
            $sitesA = new SiteRepository($a, $vault);
            $sitesB = new SiteRepository($b, $vault);
            $idA = $sitesA->save(UnitFixtures::site());
            $idB = $sitesB->save(UnitFixtures::site());
            $snapshot = $sitesB->find($idB);
            $result = ['checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['latest_release' => true]];
            $a->exec("CREATE TRIGGER refuse_credit BEFORE INSERT ON worker_progress BEGIN SELECT RAISE(ABORT,'credit-control'); END");
            foreach ([false, true] as $callerTransaction) {
                if ($callerTransaction) {
                    $a->beginTransaction();
                    $b->beginTransaction();
                    $a->exec('UPDATE installation_settings SET check_interval_minutes = 7');
                    $b->exec('UPDATE installation_settings SET check_interval_minutes = 8');
                    unlink($key); // Ownership refusal must precede even a key availability read.
                }
                $caught = null;
                try {
                    $state = new WorkerStateRepository($a, $sitesB);
                    $state->settle($snapshot, $result);
                } catch (\LogicException $error) { $caught = $error->getMessage(); unset($error); }
                Assert::same($caught, 'Settlement repository connection mismatch.');
                Assert::same($a->inTransaction(), $callerTransaction);
                Assert::same($b->inTransaction(), $callerTransaction);
                Assert::same($sitesA->find($idA)['checked_at'], null);
                Assert::same($sitesB->find($idB)['checked_at'], null);
                Assert::same((int) $a->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $b->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $a->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 0);
                if ($callerTransaction) {
                    Assert::same((int) $a->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 7);
                    Assert::same((int) $b->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 8);
                    Assert::false(is_file($key));
                    $a->rollBack();
                    $b->rollBack();
                }
            }
            file_put_contents($key, $original);
            // Same connection reaches the real credit failure, then rolls back its result.
            $state = new WorkerStateRepository($a, $sitesA);
            $caught = null;
            try { $state->settle($sitesA->find($idA), $result); }
            catch (PDOException $error) { $caught = $error->getMessage(); unset($error); }
            Assert::true(str_contains($caught ?? '', 'credit-control'));
            Assert::same($sitesA->find($idA)['checked_at'], null);
            Assert::same($sitesB->find($idB)['checked_at'], null);
            Assert::false($a->inTransaction());
            Assert::same((int) $a->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
            Assert::same(file_get_contents($key), $original);
        } finally {
            $state = $sitesA = $sitesB = $a = $b = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }
}
