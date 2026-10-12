<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;

final class ConnectionOwnershipFixture
{
    public static function create(TemporaryDirectory $directory): array
    {
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
        return [$key, $original, $vault, $a, $b, $sitesA, $sitesB, $idA, $idB, $snapshot, $result];
    }
}
