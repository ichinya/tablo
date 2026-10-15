<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use RuntimeException;
use Tablo\ClientAddress;
use Tablo\Web;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ClientAddressStartupTest
{

    #[Test]
    public function actualWebPropagatesRedactedConfigurationBeforeAnyInitialization(): void
    {
        $directory = new TemporaryDirectory('tablo-config-trace-');
        $configuration = getenv('TABLO_TRUSTED_PROXIES');
        $database = getenv('TABLO_DB');
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        $session = [session_status(), session_id(), session_name(), session_save_path(), $_SESSION ?? null];
        $marker = 'malformed-' . bin2hex(random_bytes(24));
        $inspect = null; $frame = null; $trace = null; $error = null; $caught = null;
        try {
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            putenv('TABLO_TRUSTED_PROXIES=' . $marker);
            putenv('TABLO_DB=' . $directory->path . '/unused.sqlite');
            try {
                new Web(runtimeDirectory: $directory->path . '/runtime');
            } catch (RuntimeException $exception) {
                $caught = $exception;
                unset($exception);
            }
            Assert::true($caught instanceof RuntimeException, 'Actual Web must reject malformed configuration');
            Assert::same(\Tablo\Tests\Support\FixtureValue::object($caught)->getMessage(), ClientAddress::CONFIG_ERROR);
            ConfigurationTraceAssertions::redacted(\Tablo\Tests\Support\FixtureValue::object($caught), $marker);
            Assert::true(!is_dir($directory->path . '/runtime'));
            Assert::true(!is_file($directory->path . '/unused.sqlite'));
            Assert::true(!is_file($directory->path . '/github-token.key'));
            Assert::same(scandir($directory->path), ['.', '..'], 'Preflight must create no fixture files');
            Assert::same([session_status(), session_id(), session_name(), session_save_path(), $_SESSION ?? null], $session);
        } finally {
            // Release retained exception frames before owned Windows directory cleanup, including assertion failures.
            $inspect = null; $frame = null; $trace = null; $error = null; $caught = null;
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', $ignoreArgs);
            putenv($configuration === false ? 'TABLO_TRUSTED_PROXIES' : 'TABLO_TRUSTED_PROXIES=' . $configuration);
            putenv($database === false ? 'TABLO_DB' : 'TABLO_DB=' . $database);
            $directory->close();
        }
    }
}
