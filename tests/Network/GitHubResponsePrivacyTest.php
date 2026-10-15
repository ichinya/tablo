<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Testo\Assert;
use Testo\Test;

final class GitHubResponsePrivacyTest extends GitHubPrivacyFixtureCase
{


    #[Test]
    public function realRateResponsesMaskResponseFramesAndInspectSupportedTraceState(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_RESPONSE_PRIVACY') !== '1') {
            // The runner retains preceding results in trace arguments. Exercise the same
            // real provider frames in a fresh runner, without raising the inspection bound.
            ResponsePrivacyIsolation::run(__FILE__, __FUNCTION__);
            return;
        }
        $directory = new TemporaryDirectory('tablo-github-response-r4-');
        $server = null;
        $original = ini_get('zend.exception_ignore_args');
        \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            $now = time();
            $secret = bin2hex(random_bytes(24));
            $marker = bin2hex(random_bytes(24));
            $count = $directory->path . '/count';
            $server = new TestServer($directory, dirname(__DIR__) . '/fixtures/github-response-router.php', environment: [
                'TABLO_TEST_RESPONSE_NOW' => (string) $now, 'TABLO_TEST_RESPONSE_MARKER' => $marker,
                'TABLO_TEST_RESPONSE_COUNT' => $count,
            ]);
            foreach (['manual', 'saved'] as $kind) {
                foreach (['secondary403' => 60, 'bare429' => 60, 'retry403' => 7200, 'primary429' => 7200] as $case => $delay) {
                    (new GitHubResponseScenario($directory, $server, $now, $secret, $marker))->run($kind, $case, $delay);
                }
            }
        } finally {
            unset($frame, $blocked, $again, $error, $other, $provider, $connection, $policy, $tokens, $sites, $vault, $http, $db);
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', $original);
            $server?->close();
            $directory->close();
        }
    }
}
