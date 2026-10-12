<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\GitHubConnection;
use Tablo\GitTokenRepository;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\ValidationException;
use Tablo\Tests\Support\MeasuredGitHubHttp;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubKeyTraceTest extends GitHubKeyFixtureCase
{


    #[Test]
    public function completeKeyFailureTracesProtectAllEntryArguments(): void
    {
        $directory = new TemporaryDirectory('tablo-github-key-trace-');
        $server = null;
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-budget-router.php');
            $cases = ['corrupt', 'empty', 'unreadable', 'missing'];
            if (PHP_OS_FAMILY !== 'Windows') { $cases[] = 'denied'; }
            foreach ($cases as $kind) {
                $db = Database::connect($directory->path . '/' . $kind . '.sqlite');
                $key = $directory->path . '/' . $kind . '.key';
                $vault = new TokenVault($key);
                $tokens = new GitTokenRepository($db, $vault);
                $secret = bin2hex(random_bytes(24));
                $tokens->save(['name' => 'Established', 'provider' => 'github', 'token' => $secret]);
                KeyCorruptionFixture::apply($kind, $key);
                $before = self::rows($db);
                $http = new MeasuredGitHubHttp($server->base);
                $sites = new SiteRepository($db, $vault);
                $connection = new GitHubConnection($sites, $http);
                foreach (['provider', 'branches', 'validate'] as $entry) {
                    try { self::invoke($connection, $entry, self::input($secret)); Assert::true(false, 'invalid key must fail'); }
                    catch (ValidationException $error) {
                        KeyFailureTraceAssertions::redacted($error, $secret);

                    }
                    unset($error, $cause);
                }
                Assert::same($http->core + $http->search, 0);
                Assert::same(self::rows($db), $before);
                (new KeyIntegrityAssertions($server))->retained($kind, $key, $secret);
                unset($connection, $sites, $tokens, $vault, $http, $db);
            }
        } finally {
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', (string) $original);
            unset($error, $cause, $trace, $connection, $sites, $tokens, $vault, $http, $db);
            $server?->close();
            $directory->close();
        }
    }
}
