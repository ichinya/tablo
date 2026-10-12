<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\RuntimeIni;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;
use Testo\Test;

final class RuntimeIniTest
{
    #[Test]
    public function returnsExactPreviousValueAndRestoresItWhileUnsupportedSettingRefuses(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        $memoryLimit = ini_get('memory_limit');
        $refused = false;
        try {
            Assert::same(RuntimeIni::set('zend.exception_ignore_args', value: '0'), $before);
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
            try { RuntimeIni::set('memory_limit', value: '8M'); }
            catch (\InvalidArgumentException $error) { $refused = true; Assert::same($error->getMessage(), 'Unsupported runtime privacy setting.'); }
            Assert::true($refused);
            Assert::same(ini_get('memory_limit'), $memoryLimit);
            Assert::same(ini_get('zend.exception_ignore_args'), '0');
        } finally {
            RuntimeIni::set('zend.exception_ignore_args', $before);
        }
        Assert::same(ini_get('zend.exception_ignore_args'), $before);
    }

    private static function exposed(string $marker): \RuntimeException { return new \RuntimeException('Fixed trace control.'); }
    private static function protectedValue(#[\SensitiveParameter] string $marker): \RuntimeException { return new \RuntimeException('Fixed trace control.'); }

    #[Test]
    public function actualExposedTraceIsDetectedAndSensitiveArgumentRemainsOpaque(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 3);
            return;
        }
        $previous = RuntimeIni::set('zend.exception_ignore_args', value: '0');
        $marker = bin2hex(random_bytes(24));
        try {
            Assert::true(TraceInspector::inspect(self::exposed($marker), [$marker])['leaked']);
            $protected = TraceInspector::inspect(self::protectedValue($marker), [$marker]);
            Assert::false($protected['leaked']);
            Assert::true($protected['redacted'] > 0);
        } finally {
            RuntimeIni::set('zend.exception_ignore_args', $previous);
        }
    }
}
