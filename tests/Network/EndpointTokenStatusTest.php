<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\SiteChecker;
use Tablo\SiteInput;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

/** @phpstan-import-type Endpoint from \Tablo\Tests\Support\FixtureWireRows */
final class EndpointTokenStatusTest
{
    #[Test]
    public function credentialKeepsStrictHomeAndFinalExplicitJsonSemantics(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-status-');
        $checker = null; $vault = null; $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/endpoint-token-router.php', environment: ['TABLO_ENDPOINT_FIXTURE' => $directory->path]);
            $vault = new TokenVault($directory->path . '/key');
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider(), $vault);
            $site = [...SiteInput::defaults(), 'url' => $server->base . '/home///', 'repository' => 'fixture/public', 'version_path' => '', 'endpoint_token' => $vault->encrypt(EndpointFixture::tokenA())];
            EndpointStatusAssertions::matrix($checker, $site, $server->base);
            $home = $checker->check([...$site, 'health_path' => '', 'health_check_mode' => 'json', 'health_json_path' => '$.missing']);
            Assert::same($home['online'], 1);
            $rows = self::rows($directory);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($rows, count($rows)-1)['path'], '/home///');
            Assert::same(count(array_filter($rows, static fn (array $row): bool => $row['path'] === '/destination')), 2,
                'only the two explicit endpoints follow; homepage redirects stay no-follow');
            foreach ([['==', 'ok', '$.result'], ['!=', 'bad', '$.result'], ['contains', 'o', '$.result'],
                ['>', '2', '$.number'], ['>=', '3', '$.number'], ['<', '4', '$.number'], ['<=', '3', '$.number']] as [$operator, $expected, $path]) {
                $state = $checker->check([...$site, 'health_path' => '/json', 'health_check_mode' => 'json',
                    'health_json_path' => $path, 'health_json_operator' => $operator, 'health_json_expected_value' => $expected]);
                Assert::same($state['online'], 1);
            }
            EndpointFailureAssertions::independent($checker, $site, $server->base);
            foreach (self::rows($directory) as $row) { Assert::true($row['a'] && !$row['b']); }
        } finally { $vault = null; $checker = null; $server?->close(); $directory->close(); }
    }

    /** @return list<Endpoint> */
    private static function rows(TemporaryDirectory $directory): array
    {
        $file = $directory->path . '/requests.jsonl';
        return is_file($file) ? array_map(\Tablo\Tests\Support\FixtureWireRows::endpoint(...), \Tablo\Tests\Support\FixtureValue::fetched(file($file, FILE_IGNORE_NEW_LINES))) : [];
    }
}
