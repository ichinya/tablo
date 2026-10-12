<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\SiteInput;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\RedirectServer;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectTest
{
























    #[Test]
    public function keepsFinalJsonVersionPartialGitAndGenericHomepagePolicies(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 29);
            return;
        }
        $server = new RedirectServer(); $vault = null;
        try {
            $vault = new TokenVault($server->directory->path . '/key');
            $site = [...SiteInput::defaults(), 'url' => $server->base, 'repository' => 'test/repo',
                'health_path' => '/status/301', 'version_path' => '/status/308', 'endpoint_token' => $vault->encrypt('synthetic-redirect-token')];
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider(), $vault);
            $state = $checker->check($site);
            Assert::same($state['online'], 1); Assert::same($state['deployed_version'], 'v-safe');
            Assert::same($state['health_http_status'], 200); Assert::same($state['latest_release'], 'v1.3.2');
            foreach (['false' => [0, 'json-condition', 200], 'json-invalid' => [null, 'invalid-response', 200],
                'offline' => [0, 'http', 403]] as $case => [$online, $reason, $status]) {
                $state = $checker->check([...$site, 'health_path' => '/health/' . $case, 'health_check_mode' => 'json',
                    'health_json_path' => '$.ok', 'health_json_operator' => '==', 'health_json_expected_value' => 'true']);
                Assert::same($state['online'], $online); Assert::same($state['health_error_code'], $reason);
                Assert::same($state['health_http_status'], $status); Assert::same($state['deployed_version'], 'v-safe');
            }
            $state = $checker->check([...$site, 'health_path' => '/header/missing']);
            Assert::same($state['online'], null); Assert::same($state['health_http_status'], null);
            Assert::same($state['deployed_version'], 'v-safe');
            $state = $checker->check([...$site, 'version_path' => '/header/missing']);
            Assert::same($state['online'], 1); Assert::same($state['deployed_version'], null); Assert::same($state['open_issues'], 4);
            $before = count($server->rows());
            $state = $checker->check([...$site, 'url' => $server->base . '/status/302', 'health_path' => '', 'version_path' => '']);
            Assert::same($state['online'], 0); Assert::same($state['health_http_status'], 302);
            Assert::same(count($server->rows()) - $before, 1);
            $before = count($server->rows());
            Assert::same((new HttpClient(true))->getBefore($server->base . '/status/307', [], \Tablo\MonotonicTime::integer(hrtime(true)) + 1_000_000_000)['status'], 307);
            Assert::same(count($server->rows()) - $before, 1);
            \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/framing/start', 'invalid-response');
        } finally { $vault = null; $server->close(); }
    }



    #[Test]
    public function samePdoRejectsStaleRedirectResultsAndCreditThenSettlesCurrentPartialOutcomes(): void
    {
        $server = new RedirectServer(); $state = null; $sites = null; $db = null;
        try {
            $db = \Tablo\Database::connect($server->directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $state = new \Tablo\WorkerStateRepository($db);
            $input = [...SiteInput::defaults(), 'name' => 'Redirect settlement', 'url' => $server->base, 'repository' => 'test/repo',
                'health_path' => '/status/301', 'version_path' => '/header/missing'];
            $id = $sites->save($input);
            $snapshot = $sites->find($id);
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider());
            $result = $checker->check(\Tablo\Tests\Support\FixtureValue::row($snapshot), $state::FIELDS);
            Assert::same($result['online'], 1); Assert::same($result['deployed_version'], null);
            $sites->save([...$input, 'health_path' => '/final'], $id);
            $sites->save($input, $id); // URL/endpoint ABA retains the newer revision.
            Assert::false($state->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result));
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['checked_at'], null);
            Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM worker_progress'))->fetchColumn(), 0);
            $current = $sites->find($id);
            $result = $checker->check(\Tablo\Tests\Support\FixtureValue::row($current), $state::FIELDS);
            Assert::true($state->settle(\Tablo\Tests\Support\FixtureValue::row($current), $result));
            Assert::same(\Tablo\Tests\Support\FixtureValue::row($sites->find($id))['health_http_status'], 200);
            Assert::true(min($state->turns($id, \Tablo\Tests\Support\FixtureValue::row($current)['config_revision'])) > 0);
            Assert::false($db->inTransaction());
        } finally { unset($checker, $snapshot, $current, $result); $db = null; $sites = null; $state = null; $server->close(); }
    }


}
