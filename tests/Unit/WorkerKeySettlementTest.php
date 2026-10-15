<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SharedKeyFailure;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class WorkerKeySettlementTest
{
    #[Test]
    public function settlementUsesSelectedRepositoryDespiteLaterEnvironmentChange(): void
    {
        $directory = new TemporaryDirectory('tablo-settlement-selected-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $sites = $state = $vault = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $key = $directory->path . '/key';
            file_put_contents($key, random_bytes(32));
            $vault = new TokenVault($key, true);
            $db = Database::connect(':memory:', $vault);
            $sites = new SiteRepository($db, $vault);
            $id = $sites->save(UnitFixtures::site());
            $snapshot = $sites->find($id);
            $state = new WorkerStateRepository($db, $sites);
            putenv('TABLO_TOKEN_KEY_FILE=' . $directory->path . '/unrelated-missing');
            $result = ['checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['latest_release' => true]];
            Assert::true($state->settle($snapshot, $result));
            Assert::same($sites->find($id)['checked_at'], $result['checked_at']);
            Assert::same($state->turns($id, $snapshot['config_revision'])['latest_release'], 1);
            Assert::false($db->inTransaction());
        } finally {
            $state = $sites = $db = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }

    #[Test]
    public function lostOrReplacedLifetimeKeyRefusesWithoutCreditOrCallerRollback(): void
    {
        $directory = new TemporaryDirectory('tablo-settlement-lifetime-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $db = $sites = $state = $vault = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            $key = $directory->path . '/key';
            $original = random_bytes(32);
            file_put_contents($key, $original);
            $vault = new TokenVault($key, true);
            $db = Database::connect(':memory:', $vault);
            $sites = new SiteRepository($db, $vault);
            $id = $sites->save(UnitFixtures::site());
            $snapshot = $sites->find($id);
            $state = new WorkerStateRepository($db, $sites);
            $result = ['checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['latest_release' => true]];
            foreach ([null, 'bad', random_bytes(32), random_bytes(33)] as $replacement) {
                unlink($key);
                if ($replacement !== null) { file_put_contents($key, $replacement); }
                foreach ([false, true] as $callerTransaction) {
                    if ($callerTransaction) {
                        $db->beginTransaction();
                        $db->exec('UPDATE installation_settings SET check_interval_minutes = 7');
                    }
                    $caught = null;
                    try { $state->settle($snapshot, $result); }
                    catch (SharedKeyFailure $error) { $caught = $error->getMessage(); unset($error); }
                    Assert::true(is_string($caught));
                    Assert::same($db->inTransaction(), $callerTransaction);
                    Assert::same($sites->find($id)['checked_at'], null);
                    Assert::same((int) $db->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
                    Assert::same((int) $db->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 0);
                    if ($callerTransaction) {
                        Assert::same((int) $db->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 7);
                        $db->rollBack();
                    }
                }
                Assert::false(is_file($directory->path . '/github-token.key'));
                file_put_contents($key, $original);
            }
            Assert::true($state->settle($snapshot, $result));
            Assert::same($state->turns($id, $snapshot['config_revision'])['latest_release'], 1);
        } finally {
            $state = $sites = $db = $vault = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }
}
