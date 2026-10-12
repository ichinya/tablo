<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Tablo\SiteRepository;
use Tablo\Tests\Support\HealthCheckDiagnostics;

final class RefusedCheckAssertions
{
    public function __construct(private readonly \PDO $db, private readonly SiteRepository $sites) {}

    public function homepage(array $result, int $id, string $mode): void
    {
                $diagnostic = HealthCheckDiagnostics::capture($result, $this->db);
                Assert::same($result['exit_code'], $mode === 'cli' ? 1 : 0, $diagnostic);
                Assert::same(strlen($result['stderr']), 0, $diagnostic);
                Assert::same($this->sites->find($id)['checked_at'], null, 'ABA response refuses persisted result');
                Assert::same((int) $this->db->query('SELECT count(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0, 'no stale service credit');

    }
    public function endpoint(array $result, int $id, string $mode): void
    {
                Assert::same($result['exit_code'], $mode === 'cli' ? 1 : 0, HealthCheckDiagnostics::capture($result, $this->db));
                Assert::same(strlen($result['stderr']), 0);
                Assert::same($this->sites->find($id)['checked_at'], null);
                Assert::same((int) $this->db->query('SELECT count(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0);

    }
}
