<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\DashboardStateTooLarge;
use Tablo\Presenter;
use Tablo\SavedDashboardRepository;
use Tablo\SqlStatement;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class SavedDashboardRepositoryTest
{
    #[Test]
    public function explicitProjectionReadsWithQueryOnlyAndNeverAcquiresCredentialOrFutureColumns(): void
    {
        $db = A::database();
        $git = FixtureCredentials::value('dashboard-projection-git');
        $endpoint = FixtureCredentials::value('dashboard-projection-endpoint');
        A::insert($db, 8, ['sort_order' => 1, 'version_path' => '/private-version?marker=1', 'github_token' => $git,
            'endpoint_token' => $endpoint, 'health_path' => '/private-health?marker=1']);
        A::insert($db, 2, ['sort_order' => 0]); A::insert($db, 1, ['sort_order' => 1]);
        $db->exec("ALTER TABLE sites ADD COLUMN future_secret TEXT DEFAULT 'future-secret'");
        $db->exec('DROP TABLE git_tokens');
        $before = A::snapshot($db);
        $db->exec('PRAGMA query_only=ON');
        $rows = (new SavedDashboardRepository($db))->all();
        Assert::same(array_column($rows, 'id'), [2, 1, 8]);
        Assert::same(array_column($rows, 'version_path'), ['', '', 'tracked']);
        $encoded = \Tablo\JsonEncoding::encode($rows);
        foreach ([$git, $endpoint, 'private-version', 'private-health', 'future-secret',
            'github_token', 'endpoint_token', 'git_token_id', 'selected_token_snapshot', 'future_secret'] as $marker) {
            Assert::false(str_contains($encoded, $marker));
        }
        Assert::same(Presenter::site(FixtureValue::row($rows[0] ?? null))['status'], 'Не проверен');
        Assert::same(A::snapshot($db), $before);
        A::reject(static fn () => $db->exec("UPDATE sites SET name='forbidden'"));
    }

    #[Test]
    public function moreThanOneHundredSitesAreReturnedInTheExistingDashboardOrder(): void
    {
        $db = A::database();
        for ($id = 1; $id <= 125; ++$id) { A::insert($db, $id, ['sort_order' => 126 - $id]); }
        Assert::same(array_column((new SavedDashboardRepository($db))->all(), 'id'), range(125, end: 1));
    }

    #[Test]
    public function cumulativeRawBudgetRejectsAtomicallyAndReleasesItsReadCursor(): void
    {
        $db = A::database();
        $error = str_repeat('x', times: 30_000);
        for ($id = 1; $id <= 300; ++$id) { A::insert($db, $id, ['last_error' => $error]); }
        $refused = false;
        try { (new SavedDashboardRepository($db))->all(); }
        catch (DashboardStateTooLarge) { $refused = true; }
        Assert::true($refused);
        SqlStatement::checked($db->query('UPDATE sites SET last_error=NULL'))->closeCursor();
        Assert::same(count((new SavedDashboardRepository($db))->all()), 300, 'failed read leaves connection reusable');
    }
}
