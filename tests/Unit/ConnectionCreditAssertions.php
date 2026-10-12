<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDOException;
use Tablo\WorkerStateRepository;
use Testo\Assert;

final class ConnectionCreditAssertions
{
    public function __construct(private readonly \PDO $a, private readonly \Tablo\SiteRepository $sitesA, private readonly \Tablo\SiteRepository $sitesB) {}

    /** @param array{string, string, int, int, array{checked_at: string, worker_service: array{latest_release: true}}} $expected */
    public function refused(#[\SensitiveParameter] array $expected): void
    {
        [$key, $original, $idA, $idB, $result] = $expected;
            file_put_contents($key, $original);
            // Same connection reaches the real credit failure, then rolls back its result.
            $state = new WorkerStateRepository($this->a, $this->sitesA);
            $caught = null;
            try { $state->settle(\Tablo\Tests\Support\FixtureValue::row($this->sitesA->find($idA)), $result); }
            catch (PDOException $error) { $caught = $error->getMessage(); unset($error); }
            Assert::true(str_contains($caught ?? '', 'credit-control'));
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($this->sitesA->find($idA))['checked_at'], null);
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($this->sitesB->find($idB))['checked_at'], null);
            Assert::false($this->a->inTransaction());
            Assert::same((int) \Tablo\SqlStatement::checked($this->a->query('SELECT count(*) FROM worker_progress'))->fetchColumn(), 0);
            Assert::same(file_get_contents($key), $original);

    }
}
