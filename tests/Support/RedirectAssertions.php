<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Testo\Assert;

final class RedirectAssertions
{
    public static function headers(): array { return ['Authorization: Bearer synthetic-redirect-token']; }

    public static function reject(#[\SensitiveParameter] string $url, string $expected, #[\SensitiveParameter] array $headers = [],
        int $maxRedirects = 3, ?int $deadline = null): void
    {
        $old = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            try { (new HttpClient(true))->getEndpoint($url, $headers, $maxRedirects, $deadline); }
            catch (HttpFailure $error) {
                Assert::same($error->reason, $expected);
                Assert::same($error->getPrevious(), null);
                Assert::false(TraceInspector::inspect($error, ['synthetic-redirect-token'])['leaked']);
                return;
            }
            throw new \RuntimeException('Expected bounded redirect refusal');
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $old); }
    }

}
