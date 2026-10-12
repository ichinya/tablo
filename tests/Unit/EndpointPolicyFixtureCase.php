<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\Tests\Support\TraceInspector;
use Testo\Assert;

abstract class EndpointPolicyFixtureCase
{

    protected static function protectedFailure(#[\SensitiveParameter] HttpClient $http, #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $marker): void
    {
        $previous = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            try { $http->getEndpoint($url, ['Authorization: Bearer ' . $marker]); }
            catch (HttpFailure $error) {
                $scan = TraceInspector::inspect($error, [$marker]);
                Assert::false($scan['leaked']);
                Assert::same($error->reason, 'check-error');
                Assert::same($error->getPrevious(), null);
                return;
            }
            throw new \RuntimeException('Missing bounded failure');
        } finally { \Tablo\RuntimeIni::set('zend.exception_ignore_args', $previous); }
    }
}
