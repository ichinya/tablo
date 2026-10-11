<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\EndpointFixture;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class EndpointTokenStatusTest
{
    #[Test]
    public function credentialKeepsStrictHomeExplicitJsonAndNoFollowSemantics(): void
    {
        $directory = new TemporaryDirectory('tablo-endpoint-status-');
        $server = $vault = $checker = null;
        try {
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/endpoint-token-router.php', environment: ['TABLO_ENDPOINT_FIXTURE' => $directory->path]);
            $vault = new TokenVault($directory->path . '/key');
            $checker = new SiteChecker(new HttpClient(true), new FakeProvider(), $vault);
            $site = [...SiteRepository::defaults(), 'url' => $server->base . '/home///', 'repository' => 'fixture/public', 'version_path' => '', 'endpoint_token' => $vault->encrypt(EndpointFixture::tokenA())];
            foreach ([200, 201, 204, 301, 302, 401, 403, 404, 500] as $status) {
                $home = $checker->check([...$site, 'url' => $server->base . '/home/' . $status . '///', 'health_path' => '']);
                Assert::same($home['online'], (int) ($status === 200));
                Assert::same($home['health_http_status'], $status);
                $explicit = $checker->check([...$site, 'health_path' => '/up?status=' . $status]);
                Assert::same($explicit['online'], (int) ($status >= 200 && $status < 300));
                Assert::same($explicit['health_http_status'], $status);
            }
            $home = $checker->check([...$site, 'health_path' => '', 'health_check_mode' => 'json', 'health_json_path' => '$.missing']);
            Assert::same($home['online'], 1);
            $rows = self::rows($directory);
            Assert::same($rows[count($rows)-1]['path'], '/home///');
            Assert::false(in_array('/destination', array_column($rows, 'path'), true));
            foreach ([['==', 'ok', '$.result'], ['!=', 'bad', '$.result'], ['contains', 'o', '$.result'],
                ['>', '2', '$.number'], ['>=', '3', '$.number'], ['<', '4', '$.number'], ['<=', '3', '$.number']] as [$operator, $expected, $path]) {
                $state = $checker->check([...$site, 'health_path' => '/json', 'health_check_mode' => 'json',
                    'health_json_path' => $path, 'health_json_operator' => $operator, 'health_json_expected_value' => $expected]);
                Assert::same($state['online'], 1);
            }
            $large = $checker->check([...$site, 'url' => $server->base, 'health_path' => '/large']);
            Assert::same($large['online'], null);
            Assert::same($large['health_error_code'], 'size');
            $badVersion = $checker->check([...$site, 'health_path' => '/up', 'version_path' => '/version?bad=1']);
            Assert::same($badVersion['online'], 1);
            Assert::same($badVersion['latest_release'], 'v1.3.2');
            Assert::false(str_contains($badVersion['last_error'], EndpointFixture::tokenA()));
            foreach (self::rows($directory) as $row) { Assert::true($row['a'] && !$row['b']); }
        } finally { $checker = $vault = null; $server?->close(); $directory->close(); }
    }

    private static function rows(TemporaryDirectory $directory): array
    {
        $file = $directory->path . '/requests.jsonl';
        return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file($file, FILE_IGNORE_NEW_LINES)) : [];
    }
}
