<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\JsonRecord;
use Tablo\SiteInput;
use Tablo\SiteRepository;
use Tablo\Tests\Support\DashboardModesFixture;
use Tablo\Tests\Support\DashboardStateAssertions as State;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Testo\Assert;
use Testo\Test;

final class DashboardStateTest
{
    #[Test]
    public function savedSnapshotPreservesBothPresenterViewsCredentialsAndAllCurrentData(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $db = $fixture->web->database();
            $ids = DashboardModesFixture::seed($db, $fixture->peer->base);
            $repository = new SiteRepository($db, new \Tablo\TokenVault($fixture->directory->path . '/key', external: true));
            $git = FixtureCredentials::value('dashboard-state-git');
            $endpoint = FixtureCredentials::value('dashboard-state-endpoint');
            $id = $repository->save(array_replace(SiteInput::defaults(), ['name' => 'Saved credential example',
                'url' => 'https://credentials.example.com', 'repository' => 'example/credentials', 'github_token' => $git,
                'endpoint_token' => $endpoint, 'health_path' => '/up?private_state_path_marker=1',
                'version_path' => '/version?private_state_version_marker=1']));
            $stored = FixtureValue::row($repository->find($id));
            Assert::same($fixture->web->request('/sites/' . FixtureValue::integer($ids[4] ?? null) . '/check', ['_csrf' => $fixture->csrf])['status'], 303);
            $before = A::snapshot($db);
            $key = hash_file('sha256', $fixture->directory->path . '/key');
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $response = $fixture->web->request('/dashboard/state');
                $state = State::success($response, $fixture->csrf);
                $html = FixtureValue::string($state['html'] ?? null);
                Assert::same(DashboardModesFixture::ids($html, 'tile'), array_column($repository->all(), 'id'));
                Assert::same(DashboardModesFixture::ids($html, 'table'), array_column($repository->all(), 'id'));
                State::presentedSites($html, $repository);
                foreach ([$git, $endpoint, 'private_state_path_marker', 'private_state_version_marker',
                    \Tablo\SqlColumn::string($stored, 'github_token'), \Tablo\SqlColumn::string($stored, 'endpoint_token')] as $marker) {
                    Assert::false(str_contains($response['body'], $marker));
                }
                Assert::same(A::snapshot($db), $before, 'saved sites/history/incidents/outbox/settings/schema unchanged');
                Assert::same(hash_file('sha256', $fixture->directory->path . '/key'), $key);
            }
            Assert::same($fixture->peerCount(), 0, 'saved-state polling never checks the independent health/version peer');
            Assert::false(is_file($fixture->web->directory->path . '/github-calls.log'), 'saved-state polling never invokes Git transport');
            Assert::true(str_contains($fixture->web->request('/')['body'], 'Проверки этого сайта на паузе.'), 'polling leaves notice for the next human dashboard');
        });
    }

    #[Test]
    public function savedMembershipChangesAndMoreThanOneHundredRowsProduceCompleteSnapshots(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $db = $fixture->web->database();
            for ($id = 1; $id <= 110; ++$id) { A::insert($db, $id, ['name' => 'Saved site ' . $id]); }
            $first = State::success($fixture->web->request('/dashboard/state'), $fixture->csrf);
            Assert::same(count(DashboardModesFixture::ids(FixtureValue::string($first['html'] ?? null), 'tile')), 110);
            Assert::same(JsonRecord::checked($first['stats'] ?? null), ['total' => 110, 'online' => 0, 'attention' => 110, 'paused' => 0]);
            $db->exec('DELETE FROM sites WHERE id=1');
            $db->exec("UPDATE sites SET name='Edited saved site',enabled=0,sort_order=-1 WHERE id=2");
            A::insert($db, 111, ['name' => 'Added saved site']);
            $second = State::success($fixture->web->request('/dashboard/state'), $fixture->csrf);
            $html = FixtureValue::string($second['html'] ?? null);
            $expected = range(2, end: 111);
            Assert::same(DashboardModesFixture::ids($html, 'tile'), $expected);
            Assert::same(DashboardModesFixture::ids($html, 'table'), $expected);
            Assert::same(JsonRecord::checked($second['stats'] ?? null), ['total' => 110, 'online' => 0, 'attention' => 109, 'paused' => 1]);
            Assert::true(str_contains($html, 'Edited saved site') && str_contains($html, 'Added saved site'));
            Assert::same(count(DashboardModesFixture::elements($html, '//form[@id="check-site-2"]/input[@name="_csrf"]')), 1);
            Assert::same(count(DashboardModesFixture::elements($html, '//button[@form="check-site-2" and @disabled]')), 2);
        });
    }

    #[Test]
    public function finalEncodedJsonBudgetReturnsFixed413WithoutAFragmentOrSavedWrites(): void
    {
        DashboardModesFixture::exercise(static function (DashboardModesFixture $fixture): void {
            $db = $fixture->web->database();
            A::insert($db, replace: ['last_error' => str_repeat('"', times: 600_000)]);
            $before = A::snapshot($db);
            $response = $fixture->web->request('/dashboard/state');
            Assert::same($response['status'], 413);
            Assert::same(JsonRecord::decode($response['body']), ['code' => 'too_large', 'error' => 'Dashboard state is too large.']);
            Assert::false(str_contains($response['headers'], 'Location:'));
            Assert::same(A::snapshot($db), $before);
            Assert::same($fixture->peerCount(), 0);
        });
    }

}
