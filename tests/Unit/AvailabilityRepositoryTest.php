<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\AvailabilityRepository;
use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\AvailabilityFixtures as F;
use Tablo\Tests\Support\IncidentFixtures;
use Testo\Assert;
use Testo\Test;

final class AvailabilityRepositoryTest
{
    #[Test]
    public function readsOnlyOwnedRetainedRowsAcrossRevisionsAndWindowBounds(): void
    {
        $db = Database::connect(':memory:'); $sites = new SiteRepository($db);
        $input = IncidentFixtures::site(); $id = $sites->save($input); $other = $sites->save($input);
        foreach ([['2026-03-01T00:00:00Z', 1, 0], ['2026-03-02T00:00:00Z', 0, 40],
            ['2026-02-28T23:59:59Z', null, null], ['2026-03-01T00:00:00Z', null, 999]] as [$time, $online, $ms]) {
            Assert::true(IncidentFixtures::accept($sites, $id, $online, $time, ['response_time_ms' => $ms]));
        }
        $sites->save(array_replace($input, ['name' => 'Changed']), $id);
        Assert::true(IncidentFixtures::accept($sites, $id, 0, '2026-03-01T00:12:00Z', ['response_time_ms' => 20]));
        Assert::true(IncidentFixtures::accept($sites, $other, 1, '2026-03-01T00:00:00Z'));
        $sites->save(array_replace($input, ['enabled' => false]), $id);
        $before = F::protectedRows($db); $reader = new AvailabilityRepository($db);
        $read = $reader->read($id, '2026-03-01T05:00:00+05:00', '2026-03-02T00:00:00Z');
        Assert::same(array_column($read['rows'], 'id'), [1, 4, 5]);
        Assert::same(array_column($read['rows'], 'online'), [1, null, 0]);
        Assert::same(array_column($read['rows'], 'response_time_ms'), [0, 999, 20]);
        Assert::same(array_column($read['rows'], 'config_revision'), [0, 0, 1]);
        Assert::same([$read['site']['enabled'], $read['site']['current_revision'], $read['overflow']], [0, 2, false]);
        $first = $read['rows'][0] ?? throw new \RuntimeException('Expected populated availability sample.');
        Assert::same(array_keys($first), ['id', 'config_revision', 'checked_at', 'online', 'response_time_ms']);
        Assert::same(F::protectedRows($db), $before);
        SqlStatement::checked($db->prepare('DELETE FROM check_history WHERE id = ?'))->execute([4]);
        Assert::same(array_column($reader->read($id, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z')['rows'], 'id'), [1, 5]);
        $sites->delete($id);
        Assert::true(IncidentFixtures::error(static fn () => $reader->read($id, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z')) instanceof \OutOfBoundsException);
        Assert::true(IncidentFixtures::error(static fn () => $reader->read(0, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z')) instanceof \InvalidArgumentException);
        Assert::true(IncidentFixtures::error(static fn () => $reader->read($other, '2026-03-02T00:00:00Z', '2026-03-01T00:00:00Z')) instanceof \InvalidArgumentException);
    }

    #[Test]
    public function fullBoundIsAcceptedAndOverflowReturnsNoPartialAnalytics(): void
    {
        $db = Database::connect(':memory:'); $sites = new SiteRepository($db); $id = $sites->save(IncidentFixtures::site());
        F::bulk($db, $id, AvailabilityRepository::MAXIMUM_ROWS, '2026-03-01T00:00:00Z');
        $reader = new AvailabilityRepository($db);
        $full = $reader->read($id, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z');
        Assert::same([$full['overflow'], count($full['rows'])], [false, 12_000]);
        F::bulk($db, $id, 1, '2026-03-01T00:00:00Z');
        $overflow = $reader->read($id, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z');
        Assert::same([$overflow['overflow'], $overflow['rows']], [true, []]);
        Assert::same($overflow['site'], $full['site']);
    }
}
