<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiSessionContractTest
{
    #[Test]
    public function bearerReadAtBrowserIdleEqualityDoesNotRefreshOrReviveItsCookie(): void
    {
        $fixture = new ReadApiFixture(['TABLO_TEST_SESSION_CLOCK' => '1']); $web = $fixture->web;
        try {
            $start = time(); file_put_contents($web->directory->path . '/runtime-clock', (string) $start);
            $secret = $fixture->rotate($web->authenticate());
            file_put_contents($web->directory->path . '/runtime-clock', (string) ($start + 1800));
            Assert::same($fixture->api(secret: $secret, options: ['cookies' => \Tablo\Tests\Support\CookiePolicy::Retained])['status'], 200);
            Assert::same($web->request('/settings')['status'], 303);
            Assert::same($fixture->api(secret: $secret)['status'], 200);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }

    #[Test]
    public function bearerReadsCannotTouchBrowserSessionAndLogoutCannotRevokeExternalToken(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web;
        try {
            $csrf = $web->authenticate(); $secret = $fixture->rotate($csrf);
            $sessions = glob($web->directory->path . '/runtime/sessions/sess_*');
            if ($sessions === false) { throw new \RuntimeException('Owned session inventory failed.'); }
            $before = [];
            foreach ($sessions as $path) { $before[$path] = hash_file('sha256', $path); }
            for ($i = 0; $i < 3; ++$i) { Assert::same($fixture->api(secret: $secret, options: ['cookies' => \Tablo\Tests\Support\CookiePolicy::Retained])['status'], 200); }
            foreach ($before as $path => $hash) { Assert::same(hash_file('sha256', $path), $hash); }
            Assert::same($web->request('/logout', ['_csrf' => $csrf])['status'], 303);
            Assert::same($fixture->api(secret: $secret)['status'], 200);
            Assert::same($web->request('/settings')['status'], 303);
            ReadApiFixture::error($fixture->api(options: ['cookies' => \Tablo\Tests\Support\CookiePolicy::Retained]), 401, 'unauthorized');
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
