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
final class EndpointTokenTransportTest
{
    #[Test]
    public function nativeHeadersAreExactPerSiteForDifferentAndSharedUrlsInBothOrders(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-wire-');
        $checker = null; $vault = null; $server = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/endpoint-token-router.php', environment: ['TABLO_ENDPOINT_FIXTURE' => $directory->path]);
            $vault = new TokenVault($directory->path . '/key');
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider(), $vault);
            $a = $vault->encrypt(EndpointFixture::tokenA());
            $b = $vault->encrypt(EndpointFixture::tokenB());
            foreach (['different', 'shared'] as $paths) {
                foreach (['ab', 'ba'] as $order) {
                    foreach (str_split($order) as $siteId) {
                        $site = [...SiteInput::defaults(), 'url' => $server->base . '/' . ($paths === 'shared' ? 'shared' : $siteId),
                            'repository' => 'fixture/public', 'health_path' => '/up', 'version_path' => '/version', 'endpoint_token' => $siteId === 'a' ? $a : $b];
                        $state = $checker->check($site);
                        Assert::same($state['online'], 1);
                        Assert::same($state['deployed_version'], '1.0.0');
                        $rows = self::rows($directory);
                        foreach (array_slice($rows, -2) as $row) {
                            Assert::true($row['present'] && \Tablo\Tests\Support\FixtureValue::boolean(\Tablo\Tests\Support\FixtureValue::at($row, $siteId)) && !\Tablo\Tests\Support\FixtureValue::at($row, $siteId === 'a' ? 'b' : 'a'));
                        }
                    }
                }
            }
            $site = [...SiteInput::defaults(), 'url' => $server->base . '/anonymous/', 'repository' => 'fixture/public', 'health_path' => '', 'version_path' => ''];
            $before = count(self::rows($directory));
            $state = $checker->check($site);
            $rows = self::rows($directory);
            Assert::same(count($rows), $before + 1);
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($rows, $before)['path'], '/anonymous/');
            Assert::false(\Tablo\Tests\Support\FixtureValue::at($rows, $before)['present']);
            Assert::same($state['online'], 1);
            Assert::same(count($rows), 17);
        } finally { $vault = null; $checker = null; $server?->close(); $directory->close(); }
    }

    /** @return list<Endpoint> */
    private static function rows(TemporaryDirectory $directory): array
    {
        $file = $directory->path . '/requests.jsonl';
        return is_file($file) ? array_map(\Tablo\Tests\Support\FixtureWireRows::endpoint(...), \Tablo\Tests\Support\FixtureValue::fetched(file($file, FILE_IGNORE_NEW_LINES))) : [];
    }
}
