<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\HttpClient;

use RuntimeException;
use LogicException;
use Testo\Assert;
use Testo\Test;

final class HttpClientTest
{
    #[Test]
    public function rejectsSsrfTargets(): void
    {
        foreach (['http://127.0.0.1/up', 'http://10.0.0.1/up', 'http://[::1]/up', 'http://[::ffff:127.0.0.1]/up', 'file:///etc/passwd'] as $url) {
            try {
                (new HttpClient())->get($url);
                throw new LogicException('local request accepted');
            } catch (RuntimeException $e) {
                Assert::true(!($e instanceof LogicException), 'local request accepted');
            }
        }
    }
}
