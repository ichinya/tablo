<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\Tests\Support\RedirectServer;
use Testo\Assert;
use Testo\Test;

final class RedirectAdmissionTest
{

    #[Test]
    public function explicitPrivatePermissionRetainsNativeLocalhostResolutionAndPublicRefusal(): void
    {
        $server = new RedirectServer();
        try {
            $url = 'http://localhost:' . \Tablo\Tests\Support\FixtureValue::integer(parse_url($server->base, PHP_URL_PORT)) . '/chain/3';
            $http = new HttpClient(true);
            Assert::same($http->get($url, \Tablo\Tests\Support\RedirectAssertions::headers())['status'], 302, 'existing generic boundary resolves localhost natively');
            Assert::same(count($server->rows()), 1);
            Assert::same($http->getEndpoint($url, \Tablo\Tests\Support\RedirectAssertions::headers())['status'], 200);
            Assert::same(count($server->rows()), 5, 'four real endpoint requests use native resolution without resolver doubles');
            foreach ($server->rows() as $row) { Assert::true($row['bearer']); }
            $reason = null;
            try { (new HttpClient())->getEndpoint($url, \Tablo\Tests\Support\RedirectAssertions::headers()); }
            catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::true(in_array($reason, ['dns', 'ssrf'], strict: true), 'public mode refuses OS-only or private localhost answers');
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
                $response = $http->getEndpoint($server->base . '/status/' . $status, \Tablo\Tests\Support\RedirectAssertions::headers());
                Assert::same($response['status'], 200);
                Assert::same(count($server->rows()) - $before, 2);
                Assert::false((($response['location'] ?? null) !== null) || str_contains(\Tablo\Tests\Support\FixtureValue::string(json_encode($response)), 'synthetic-redirect-token'));
            }
            foreach (['root', 'sibling', 'dot', 'query', 'network', 'case'] as $case) {
                $before = count($server->rows());
                Assert::same($http->getEndpoint($server->base . '/relative/start?case=' . $case, \Tablo\Tests\Support\RedirectAssertions::headers())['status'], 200);
                Assert::same(count($server->rows()) - $before, 2);
            }
            foreach ([300, 304, 305] as $status) {
                $before = count($server->rows());
                Assert::same($http->getEndpoint($server->base . '/status/' . $status)['status'], $status);
                Assert::same(count($server->rows()) - $before, 1);
            }
            foreach ($server->rows() as $row) { Assert::true($row['method_get']); Assert::false($row['unexpected_headers']); }
            foreach (array_slice($server->rows(), offset: 0, length: 22) as $row) { Assert::true($row['bearer']); }
        } finally { $server->close(); }
    }
}
