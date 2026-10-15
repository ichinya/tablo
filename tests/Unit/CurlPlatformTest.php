<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\InstallationException;
use Tablo\Tests\CurlPlatformSimulation\Capabilities;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

require_once dirname(__DIR__) . '/fixtures/curl-platform-simulation.php';

final class CurlPlatformTest
{
    #[Test]
    public function simulatedVersionAndOptionRequirementsAreBothEnforced(): void
    {
        $directory = new TemporaryDirectory('tablo-curl-simulation-');
        try {
            mkdir($directory->path . '/app');
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
            $source = str_replace('declare(strict_types=1);',
                'declare(strict_types=1); namespace Tablo\\Tests\\CurlPlatformSimulation;', $source);
            file_put_contents($directory->path . '/app/bootstrap.php', $source);
            foreach ([[true, true, 0x071d00, false], [true, true, 0x073600, false],
                [true, true, 0x073700, true], [true, true, 0x081100, true],
                [true, false, 0x081100, false], [false, true, 0x081100, false],
                [true, true, null, false]] as [$available, $option, $version, $supported]) {
                Capabilities::$available = $available;
                Capabilities::$option = $option;
                Capabilities::$version = $version;
                $error = null;
                try { require $directory->path . '/app/bootstrap.php'; }
                catch (InstallationException $caught) { $error = $caught; }
                Assert::same($error === null, $supported);
                if (!$supported) {
                    Assert::same($error->getCode(), InstallationException::UNSUPPORTED_CURL);
                    Assert::same($error->getMessage(), InstallationException::CURL_DIAGNOSTIC);
                    Assert::same($error->getPrevious(), null);
                }
            }
            } catch (\Throwable $error) {
            $directory->retainFailure($error);
            throw $error;
        } finally { $directory->close(); }
    }

    #[Test]
    public function actualBootstrapRefusesDisabledCapabilityBeforeStorageWithSafeCliDiagnosis(): void
    {
        $directory = new TemporaryDirectory('tablo-curl-platform-');
        try {
            foreach ([['bin/check.php'], ['bin/worker.php'], ['bin/key-preflight.php'],
                ['bin/admin-password.php', '--show-installation']] as $arguments) {
                // CLI OPcache on the pinned Windows runtime misdispatches disabled
                // functions. Keep this negative-capability child uncached; the
                // ordinary runtime and the actual bootstrap guard stay unchanged.
                $result = Subprocess::run([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'disable_functions=curl_version', ...$arguments],
                    $directory, ['TABLO_DB' => $directory->path . '/must-not-exist.sqlite',
                        'TABLO_TOKEN_KEY_FILE' => $directory->path . '/must-not-exist.key']);
                Assert::same($result['exit_code'], $arguments[0] === 'bin/admin-password.php' ? 3 : 1);
                Assert::same($result['stdout'], '');
                Assert::true(str_contains($result['stderr'], InstallationException::CURL_DIAGNOSTIC));
                Assert::true(!str_contains($result['stderr'], $directory->path));
                Assert::true(!is_file($directory->path . '/must-not-exist.sqlite'));
                Assert::true(!is_file($directory->path . '/must-not-exist.key'));
            }
        } catch (\Throwable $error) {
            $directory->retainFailure($error);
            throw $error;
        } finally { $directory->close(); }
    }
}
