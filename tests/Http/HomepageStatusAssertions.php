<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;

use Tablo\Tests\Support\HealthCheckDiagnostics;

/**
 * @phpstan-import-type Result from \Tablo\Tests\Support\Subprocess
 * @phpstan-import-type Record from \Tablo\SqlSitesDeployedVersionHealthErrorCodeHealthHttpStatusRow as Status
 */
final class HomepageStatusAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    /**
     * @param Status $manual
     */
    public static function manual(array $manual, int $status): void
    {
            Assert::same($manual['online'], $status === 200 ? 1 : 0);
            Assert::same($manual['health_http_status'], $status);
            Assert::same($manual['health_error_code'], $status === 200 ? null : 'http');
            Assert::same($manual['deployed_version'], null);
            Assert::same($manual['open_issues'], 2);

    }
    /**
     * @param Result $result
     * @param Status $manual
     */
    public function cli(array $result, int $status, int $historyBeforeCli, array $manual): void
    {
            $diagnostic = HealthCheckDiagnostics::capture($result, $this->web->database());
            Assert::same($result['exit_code'], $status === 200 ? 0 : 1, $diagnostic);
            Assert::same(strlen($result['stderr']), 0, $diagnostic);
            Assert::same($result['exit_code'], $status === 200 ? 0 : 1, $result['stderr']);
            Assert::same($result['stderr'], '');
            Assert::same((int) \Tablo\SqlStatement::checked($this->web->database()->query('SELECT COUNT(*) FROM check_history WHERE site_id=1'))->fetchColumn(), $historyBeforeCli + 1,
                'actual one-shot CLI appends with its accepted result');
            Assert::same(\Tablo\SqlRows::one(\Tablo\SqlStatement::checked($this->web->database()->query('SELECT online,health_error_code,health_http_status,deployed_version,latest_commit,open_issues,last_error FROM sites WHERE id=1'))->fetch(), \Tablo\SqlSitesDeployedVersionHealthErrorCodeHealthHttpStatusRow::checked(...)), $manual, 'manual and CLI use one policy');

    }
}
