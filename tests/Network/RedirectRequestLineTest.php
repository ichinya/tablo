<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\Tests\Support\RedirectServer;
use Testo\Assert;
use Testo\Test;

final class RedirectRequestLineTest
{
    #[Test]
    public function nativeRequestLineDistinguishesAbsentEmptyAndNonemptyQueryResources(): void
    {
        $server = new RedirectServer();
        try {
            foreach (['/query/resource' => 404, '/query/resource?' => 200, '/query/resource?x=' => 201] as $target => $status) {
                $before = count($server->rows());
                $response = (new HttpClient(true))->getEndpoint($server->base . $target, \Tablo\Tests\Support\RedirectAssertions::headers());
                Assert::same($response['status'], $status, 'receiver status distinguishes query presence');
                Assert::same(json_decode($response['body'], associative: true)['query_resource'], $status);
                $rows = array_slice($server->rows(), $before);
                Assert::same(count($rows), 1);
                Assert::same($rows[0]['request_line'], 'GET ' . $target . ' HTTP/1.1');
                Assert::true($rows[0]['bearer']); Assert::false($rows[0]['unexpected_headers']);
            }
        } finally { $server->close(); }
    }

    #[Test]
    public function nativeRedirectRequestLinesPreserveQueryClearingEncodingAndLaterHops(): void
    {
        $server = new RedirectServer();
        try {
            $vectors = [
                [200, ['/query/start-empty', '/query/resource?']],
                [201, ['/query/start-nonempty', '/query/resource?x=']],
                [404, ['/query/start-absent?inherited=1', '/query/resource']],
                [200, ['/query/query-only?old=1', '/query/query-only?']],
                [201, ['/query/query-nonempty?old=1', '/query/query-nonempty?x=']],
                [200, ['/query/later?inherited=1', '/query/step?replace=1', '/query/step?', '/query/resource?']],
                [202, ['/query/encoded-start', '/query/%2E%2e/%2F%3F%23%25?value=%2f%3F%23%25&literal=+']],
            ];
            foreach ($vectors as [$status, $targets]) {
                $before = count($server->rows());
                $response = (new HttpClient(true))->getEndpoint($server->base . $targets[0], \Tablo\Tests\Support\RedirectAssertions::headers());
                Assert::same($response['status'], $status, 'receiver rejects any changed target');
                Assert::same(json_decode($response['body'], associative: true)['query_resource'], $status);
                $rows = array_slice($server->rows(), $before);
                Assert::same(array_column($rows, 'request_line'), array_map(static fn (string $target): string => 'GET ' . $target . ' HTTP/1.1', $targets));
                foreach ($rows as $row) { Assert::true($row['bearer']); Assert::false($row['unexpected_headers']); }
            }
        } finally { $server->close(); }
    }

}
