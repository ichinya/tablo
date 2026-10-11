<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\RedirectServer;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;
use Testo\Test;

final class EndpointRedirectTest
{
    #[Test]
    public function explicitPrivatePermissionRetainsNativeLocalhostResolutionAndPublicRefusal(): void
    {
        $server = new RedirectServer();
        try {
            $url = 'http://localhost:' . parse_url($server->base, PHP_URL_PORT) . '/chain/3';
            $http = new HttpClient(true);
            Assert::same($http->get($url, self::headers())['status'], 302, 'existing generic boundary resolves localhost natively');
            Assert::same(count($server->rows()), 1);
            Assert::same($http->getEndpoint($url, self::headers())['status'], 200);
            Assert::same(count($server->rows()), 5, 'four real endpoint requests use native resolution without resolver doubles');
            foreach ($server->rows() as $row) { Assert::true($row['bearer']); }
            $reason = null;
            try { (new HttpClient())->getEndpoint($url, self::headers()); }
            catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::true(in_array($reason, ['dns', 'ssrf'], true), 'public mode refuses OS-only or private localhost answers');
            Assert::same(count($server->rows()), 5, 'public mode never sends credentials to localhost');
        } finally { $server->close(); }
    }

    #[Test]
    public function actualGetFollowsOnlyFiveStatusesAndRfcRelativeReferences(): void
    {
        $server = new RedirectServer();
        try {
            $http = new HttpClient(true);
            foreach ([301, 302, 303, 307, 308] as $status) {
                $before = count($server->rows());
                $response = $http->getEndpoint($server->base . '/status/' . $status, self::headers());
                Assert::same($response['status'], 200);
                Assert::same(count($server->rows()) - $before, 2);
                Assert::false(isset($response['location']) || str_contains(json_encode($response), 'synthetic-redirect-token'));
            }
            foreach (['root', 'sibling', 'dot', 'query', 'network', 'case'] as $case) {
                $before = count($server->rows());
                Assert::same($http->getEndpoint($server->base . '/relative/start?case=' . $case, self::headers())['status'], 200);
                Assert::same(count($server->rows()) - $before, 2);
            }
            foreach ([300, 304, 305] as $status) {
                $before = count($server->rows());
                Assert::same($http->getEndpoint($server->base . '/status/' . $status)['status'], $status);
                Assert::same(count($server->rows()) - $before, 1);
            }
            foreach ($server->rows() as $row) { Assert::true($row['method_get']); Assert::false($row['unexpected_headers']); }
            foreach (array_slice($server->rows(), 0, 22) as $row) { Assert::true($row['bearer']); }
        } finally { $server->close(); }
    }

    #[Test]
    public function limitsTransitionsAndRejectsCyclesBeforeAnyRepeatRequest(): void
    {
        $server = new RedirectServer();
        try {
            foreach ([1, 2, 3] as $limit) {
                $before = count($server->rows());
                Assert::same((new HttpClient(true))->getEndpoint($server->base . '/chain/' . $limit, [], $limit)['status'], 200);
                Assert::same(count($server->rows()) - $before, $limit + 1);
                $before = count($server->rows());
                self::reject($server->base . '/chain/' . ($limit + 1), 'redirect-limit', maxRedirects: $limit);
                Assert::same(count($server->rows()) - $before, $limit + 1);
            }
            $before = count($server->rows());
            self::reject($server->base . '/cycle/a', 'redirect-cycle');
            Assert::same(count($server->rows()) - $before, 2);
        } finally { $server->close(); }
    }

    #[Test]
    public function rejectsOtherOriginsWithZeroDestinationHitsAndNoCredentialForwarding(): void
    {
        $source = new RedirectServer(); $destination = new RedirectServer();
        try {
            foreach (['scheme', 'host', 'private', 'port?port=' . parse_url($destination->base, PHP_URL_PORT)] as $case) {
                $before = count($source->rows());
                self::reject($source->base . '/cross/' . $case, 'redirect-origin', headers: self::headers());
                Assert::same(count($source->rows()) - $before, 1);
                Assert::same($destination->rows(), []);
                Assert::true($source->rows()[$before]['bearer']);
            }
            foreach ([['Cookie: synthetic-redirect-token'], ['Host: other.example'], ['X-Key: synthetic-redirect-token'],
                ['Authorization: Bearer synthetic-redirect-token', 'Referer: secret'], ["Authorization: Bearer x\r\nX: y"]] as $headers) {
                $before = count($source->rows());
                self::reject($source->base . '/final', 'check-error', headers: $headers);
                Assert::same(count($source->rows()), $before);
            }
        } finally { $destination->close(); $source->close(); }
    }

    #[Test]
    public function rejectsMissingEmptyDuplicateHostileLocationAndUsesOnlyFinalHeaders(): void
    {
        $server = new RedirectServer();
        try {
            foreach (['missing', 'empty'] as $case) { self::reject($server->base . '/header/' . $case, 'redirect-location-missing'); }
            foreach (['duplicate', 'conflicting', 'folded', 'control', 'userinfo', 'fragment', 'malformed'] as $case) {
                $before = count($server->rows());
                self::reject($server->base . '/header/' . $case, 'redirect-location-invalid', headers: self::headers());
                Assert::same(count($server->rows()) - $before, 1);
            }
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/interim')['status'], 200);
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/interim-identical')['status'], 200);
            $before = count($server->rows());
            $outcome = [];
            try {
                $response = (new HttpClient(true))->getEndpoint($server->base . '/header/interim-conflicting');
                Assert::same($response['status'], 200);
                Assert::same(count($server->rows()) - $before, 2, 'only an unambiguous final Location can route');
                $outcome = ['status' => 200];
            } catch (HttpFailure $error) {
                Assert::same($error->reason, 'redirect-location-invalid');
                Assert::same(count($server->rows()) - $before, 1, 'libcurl refuses ambiguous Location before final callback');
                $outcome = ['reason' => $error->reason];
            }
            $report = getenv('TABLO_REDIRECT_CONTROL_REPORT');
            if ($report) { file_put_contents($report, json_encode(['curl_version' => curl_version()['version'],
                'interim_conflict' => $outcome]) . "\n", FILE_APPEND | LOCK_EX); }
            $before = count($server->rows());
            Assert::same((new HttpClient(true))->getEndpoint($server->base . '/header/trailer')['status'], 200);
            Assert::same(count($server->rows()) - $before, 1);
        } finally { $server->close(); }
    }

    #[Test]
    public function nativeChainSharesBodyHeaderAndRemainingDeadlineBudgets(): void
    {
        $server = new RedirectServer();
        try {
            foreach (['body', 'headers', 'combined'] as $case) { self::reject($server->base . '/budget/' . $case, 'size'); }
            $body = (new HttpClient(true))->getEndpoint($server->base . '/budget/tiny-chunks')['body'];
            Assert::same(strlen($body), 200000, 'decoded-read cap excludes raw chunk framing and TLS');
            $before = count($server->rows());
            $start = hrtime(true);
            self::reject($server->base . '/delay/start', 'timeout', deadline: $start + 180_000_000);
            Assert::same(count($server->rows()) - $before, 2);
            Assert::true((hrtime(true) - $start) / 1e6 < 900, 'remaining cURL deadline spans both transfers');
            $slow = new class extends HttpClient {
                public int $calls = 0;
                protected function resolve(#[\SensitiveParameter] string $host): array { ++$this->calls; usleep(40000); return ['8.8.8.8']; }
            };
            $reason = null; $start = hrtime(true);
            try { $slow->getEndpoint('https://synthetic.example/up', [], deadline: $start + 10_000_000); }
            catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::same($reason, 'timeout'); Assert::same($slow->calls, 1);
            Assert::true(hrtime(true) - $start > 10_000_000, 'synchronous resolver overrun is qualified, not a hard DNS bound');
        } finally { $server->close(); }
    }

    #[Test]
    public function keepsFinalJsonVersionPartialGitAndGenericHomepagePolicies(): void
    {
        $server = new RedirectServer(); $vault = null;
        try {
            $vault = new TokenVault($server->directory->path . '/key');
            $site = [...SiteRepository::defaults(), 'url' => $server->base, 'repository' => 'test/repo',
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
            Assert::same((new HttpClient(true))->getBefore($server->base . '/status/307', [], hrtime(true) + 1_000_000_000)['status'], 307);
            Assert::same(count($server->rows()) - $before, 1);
            self::reject($server->base . '/framing/start', 'invalid-response');
        } finally { $vault = null; $server->close(); }
    }

    private static function headers(): array { return ['Authorization: Bearer synthetic-redirect-token']; }

    #[Test]
    public function samePdoRejectsStaleRedirectResultsAndCreditThenSettlesCurrentPartialOutcomes(): void
    {
        $server = new RedirectServer(); $db = $sites = $state = null;
        try {
            $db = \Tablo\Database::connect($server->directory->path . '/db.sqlite');
            $sites = new SiteRepository($db);
            $state = new \Tablo\WorkerStateRepository($db);
            $input = [...SiteRepository::defaults(), 'name' => 'Redirect settlement', 'url' => $server->base, 'repository' => 'test/repo',
                'health_path' => '/status/301', 'version_path' => '/header/missing'];
            $id = $sites->save($input);
            $snapshot = $sites->find($id);
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider());
            $result = $checker->check($snapshot, $state::FIELDS);
            Assert::same($result['online'], 1); Assert::same($result['deployed_version'], null);
            $sites->save([...$input, 'health_path' => '/final'], $id);
            $sites->save($input, $id); // URL/endpoint ABA retains the newer revision.
            Assert::false($state->settle($snapshot, $result));
            Assert::same($sites->find($id)['checked_at'], null);
            Assert::same((int) $db->query('SELECT count(*) FROM worker_progress')->fetchColumn(), 0);
            $current = $sites->find($id);
            $result = $checker->check($current, $state::FIELDS);
            Assert::true($state->settle($current, $result));
            Assert::same($sites->find($id)['health_http_status'], 200);
            Assert::true(min($state->turns($id, $current['config_revision'])) > 0);
            Assert::false($db->inTransaction());
        } finally { unset($checker, $snapshot, $current, $result); $state = $sites = $db = null; $server->close(); }
    }

    private static function reject(#[\SensitiveParameter] string $url, string $expected, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): void
    {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            try { (new HttpClient(true))->getEndpoint($url, $headers, $maxRedirects, $deadline); }
            catch (HttpFailure $error) {
                Assert::same($error->reason, $expected);
                Assert::same($error->getPrevious(), null);
                Assert::false(TraceInspector::inspect($error, ['synthetic-redirect-token'])['leaked']);
                return;
            }
            throw new \RuntimeException('Expected bounded redirect refusal');
        } finally { ini_set('zend.exception_ignore_args', $old); }
    }
}
