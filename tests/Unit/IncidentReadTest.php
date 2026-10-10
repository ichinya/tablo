<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\Database;
use Tablo\IncidentRepository;
use Tablo\SiteRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Testo\Assert;
use Testo\Test;

final class IncidentReadTest
{
    #[Test]
    public function boundedCanonicalScopedPagesKeepCeilingAcrossRecoveryDeleteAndNewBackdatedOpening(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        $other = $sites->save(F::site());
        for ($i = 0; $i < 115; $i++) {
            F::accept($sites, $i % 2 ? $id : $other, 0, '2026-01-01T00:00:00Z');
            F::accept($sites, $i % 2 ? $id : $other, 1, '2026-01-01T00:01:00Z');
        }
        $read = new IncidentRepository($db);
        $first = $read->page();
        Assert::same(count($first['rows']), 50);
        Assert::true(strlen($first['next_cursor']) <= 256);
        F::accept($sites, $id, 0, '2025-01-01T00:00:00Z');
        $second = $read->page(cursor: $first['next_cursor']);
        $third = $read->page(cursor: $second['next_cursor']);
        Assert::same(count($second['rows']), 50);
        Assert::same(count($third['rows']), 15);
        $ids = array_merge(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'), array_column($third['rows'], 'id'));
        Assert::same(count(array_unique($ids)), 115);
        Assert::false(in_array(231, $ids, true));
        Assert::same(count($read->page(limit: 100)['rows']), 100);
        $scoped = $read->page($id, 1);
        Assert::same($scoped['rows'][0]['site_id'], $id);
        Assert::instanceOf(F::error(fn () => $read->page($other, 1, $scoped['next_cursor'])), \InvalidArgumentException::class);
        $before = F::rows($db);
        $db->exec('PRAGMA query_only=ON');
        $read->page($id, 1, $scoped['next_cursor']);
        Assert::same(F::rows($db), $before);
        $db->exec('PRAGMA query_only=OFF');
        $sites->delete($other);
        Assert::instanceOf(F::error(fn () => $read->page($other)), \OutOfBoundsException::class);
        Assert::same($read->page(cursor: $first['next_cursor'])['rows'][0]['site_id'], $id);
    }

    #[Test]
    public function malformedCursorsLimitsAndSqlPlansRefuseCoercionsAndUseJustifiedIndexes(): void
    {
        $db = Database::connect(':memory:');
        $sites = new SiteRepository($db);
        $id = $sites->save(F::site());
        $read = new IncidentRepository($db);
        foreach ([0, 101, -1] as $limit) { Assert::instanceOf(F::error(fn () => $read->page(limit: $limit)), \InvalidArgumentException::class); }
        foreach (['', str_repeat('a', 257), 'a=', '%%%%', base64_encode('{}'), base64_encode('{"v":1,"site":null,"before":"1","ceiling":2}')]
            as $cursor) { Assert::instanceOf(F::error(fn () => $read->page(cursor: $cursor)), \InvalidArgumentException::class); }
        foreach (['{"v":1,"site":null,"before":1.0,"ceiling":2}', '{"v":1,"site":null,"before":-1,"ceiling":2}',
            '{"v":1,"site":null,"before":1,"ceiling":2,"extra":0}', '{"v":1,"v":1,"site":null,"before":1,"ceiling":2}',
            '{ "v":1,"site":null,"before":1,"ceiling":2}', '{"v":1,"site":[],"before":1,"ceiling":2}'] as $json) {
            $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
            Assert::instanceOf(F::error(fn () => $read->page(cursor: $cursor)), \InvalidArgumentException::class);
        }
        Assert::instanceOf(F::error(fn () => $read->page(999)), \OutOfBoundsException::class);
        foreach ([
            ['SELECT id FROM incidents WHERE site_id=1 AND end_reason IS NULL', 'incidents_unresolved'],
            ['SELECT * FROM incidents WHERE site_id=1 AND id<=20 AND id<10 ORDER BY id DESC LIMIT 51', 'incidents_site_id'],
            ['SELECT * FROM incidents WHERE id<=20 AND id<10 ORDER BY id DESC LIMIT 51', 'INTEGER PRIMARY KEY'],
        ] as [$sql, $index]) {
            $plan = implode(' ', array_column($db->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(), 'detail'));
            Assert::true(str_contains($plan, $index), $plan);
            Assert::false(str_contains($plan, 'TEMP B-TREE'));
        }
        F::accept($sites, $id, 0, '2026-01-01T00:00:00Z');
        Assert::instanceOf(F::error(fn () => $db->exec("INSERT INTO incidents(id,site_id,config_revision,opened_at) VALUES(2,1,0,'2026-01-01T00:00:00Z')")), \PDOException::class);
        Assert::same(count($read->page()['rows']), 1, 'partial unique index rejects second unresolved');
    }
}
