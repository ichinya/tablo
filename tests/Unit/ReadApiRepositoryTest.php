<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\ApiSiteDto;
use Tablo\ReadApiRepository;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class ReadApiRepositoryTest
{
    #[Test]
    public function keysetSentinelOrderDetailAndMaximumIdentityAvoidOffsetAndCredentialProjection(): void
    {
        $db = A::database();
        foreach ([8, 1, 40, PHP_INT_MAX] as $id) { A::insert($db, $id); }
        $db->exec("ALTER TABLE sites ADD COLUMN future_secret TEXT DEFAULT 'future-secret-canary'");
        $repository = new ReadApiRepository($db); $rows = $repository->page('0', 2);
        Assert::same(array_column($rows, 'id'), ['1','8','40']);
        Assert::same(array_column($repository->page('8', 2), 'id'), ['40',(string) PHP_INT_MAX]);
        Assert::same($repository->page((string) PHP_INT_MAX), []);
        Assert::null($repository->find('2'));
        $row = $repository->find((string) PHP_INT_MAX); Assert::notNull($row);
        Assert::same($row['id'] ?? null, '9223372036854775807');
        foreach (['github_token','endpoint_token','git_token_id','selected_token_snapshot','future_secret','last_error','health_path','version_path'] as $column) { Assert::false(array_key_exists($column, $row)); }
        A::exactDto(ApiSiteDto::fromRow($row, A::NOW));
        foreach (['JOIN','COUNT','OFFSET','SELECT s.*'] as $forbidden) { Assert::false(str_contains(ReadApiRepository::projection(), $forbidden)); }
        $db = null;
    }

    #[Test]
    public function actualPlannerUsesPrimaryKeyRangeAndReadOnlyReadsLeaveAllRowsUnchanged(): void
    {
        $db = A::database(); A::insert($db); $before = A::snapshot($db);
        $plan = A::rows($db, 'EXPLAIN QUERY PLAN SELECT ' . ReadApiRepository::projection() . ' FROM main.sites WHERE main.sites.id > 0 ORDER BY main.sites.id ASC LIMIT 51');
        Assert::true(str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'USING INTEGER PRIMARY KEY (rowid>?)'));
        Assert::false(str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'TEMP B-TREE'));
        $db->exec('PRAGMA query_only=ON'); $reader = new ReadApiRepository($db);
        $reader->page(); $reader->find('1'); Assert::same(A::snapshot($db), $before);
        A::reject(static fn () => $db->exec("UPDATE sites SET name='forbidden'"));
        Assert::same(A::snapshot($db), $before); $db = null;
    }

    #[Test]
    public function byteAcquisitionIsBoundedBeforeLegacyTextValidation(): void
    {
        $db = A::database(); A::insert($db, replace: ['name' => str_repeat('x', times: 100_000), 'deployed_version' => str_repeat('x', times: 100_000)]);
        $row = (new ReadApiRepository($db))->find('1'); Assert::notNull($row);
        Assert::same(strlen(\Tablo\Tests\Support\FixtureValue::string($row['name'] ?? null)), 321);
        Assert::same(strlen(\Tablo\Tests\Support\FixtureValue::string($row['deployed_version'] ?? null)), 257);
        A::reject(static fn () => ApiSiteDto::fromRow($row, A::NOW));
        $statement = \Tablo\SqlStatement::checked($db->prepare('UPDATE sites SET name=:name,sort_order=:sort_order'));
        $statement->bindValue(':name', str_repeat('x', times: 100_000), \PDO::PARAM_LOB);
        $statement->bindValue(':sort_order', str_repeat('x', times: 100_000), \PDO::PARAM_LOB);
        try { $statement->execute(); } finally { $statement->closeCursor(); }
        $blob = (new ReadApiRepository($db))->find('1'); Assert::notNull($blob);
        Assert::same($blob['name'] ?? null, 0); Assert::same($blob['sort_order'] ?? null, 'invalid');
        A::reject(static fn () => ApiSiteDto::fromRow($blob, A::NOW)); unset($statement); $db = null;
    }
}
