<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Testo\Assert;

final class ConnectionOwnershipAssertions
{
    public function __construct(private readonly \PDO $a, private readonly \PDO $b) {}

    public function noCredit(): void
    {
                Assert::same((int) $this->a->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $this->b->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
                Assert::same((int) $this->a->query('SELECT fairness_turn FROM worker_runtime')->fetchColumn(), 0);

    }
    public function caller(string $key): void
    {
                    Assert::same((int) $this->a->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 7);
                    Assert::same((int) $this->b->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 8);
                    Assert::false(is_file($key));
                    $this->a->rollBack();
                    $this->b->rollBack();

    }
}
