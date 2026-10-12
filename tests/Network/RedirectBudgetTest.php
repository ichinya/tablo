<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\HttpClient;
use Tablo\HttpFailure;
use Tablo\Tests\Support\RedirectServer;
use Testo\Assert;
use Testo\Test;

final class RedirectBudgetTest
{
    #[Test]
    public function limitsTransitionsAndRejectsCyclesBeforeAnyRepeatRequest(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 4);
            return;
        }
        $server = new RedirectServer();
        try {
            foreach ([1, 2, 3] as $limit) {
                $before = count($server->rows());
                Assert::same((new HttpClient(true))->getEndpoint($server->base . '/chain/' . $limit, [], $limit)['status'], 200);
                Assert::same(count($server->rows()) - $before, $limit + 1);
                $before = count($server->rows());
                \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/chain/' . ($limit + 1), 'redirect-limit', maxRedirects: $limit);
                Assert::same(count($server->rows()) - $before, $limit + 1);
            }
            $before = count($server->rows());
            \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/cycle/a', 'redirect-cycle');
            Assert::same(count($server->rows()) - $before, 2);
        } finally { $server->close(); }
    }

    #[Test]
    public function nativeChainSharesBodyHeaderAndRemainingDeadlineBudgets(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 4);
            return;
        }
        $server = new RedirectServer();
        try {
            foreach (['body', 'headers', 'combined'] as $case) { \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/budget/' . $case, 'size'); }
            $body = (new HttpClient(true))->getEndpoint($server->base . '/budget/tiny-chunks')['body'];
            Assert::same(strlen($body), 200_000, 'decoded-read cap excludes raw chunk framing and TLS');
            $before = count($server->rows());
            $start = hrtime(true);
            \Tablo\Tests\Support\RedirectAssertions::reject($server->base . '/held/start', 'timeout',
                \Tablo\Tests\Support\RedirectAssertions::headers(), deadline: $start + 180_000_000);
            Assert::true((hrtime(true) - $start) / 1e6 < 900, 'first-hop refusal keeps the original upper bound');
            $sent = array_slice($server->rows(), $before);
            if ($sent === []) {
                Assert::same($sent, [], 'pre-send expiry has no held-response receipt');
            }
            if ($sent !== []) {
                Assert::same(count($sent), 1, 'an observed held request cannot advance to another hop');
                Assert::same($sent[0]['route'], 'held');
                Assert::true($sent[0]['bearer'], 'the observed request carries the protected credential');
            }
            $slow = new class extends HttpClient {
                public int $calls = 0;
                protected function resolve(#[\SensitiveParameter] string $host): array { ++$this->calls; usleep(40_000); return ['8.8.8.8']; }
            };
            $reason = null; $start = hrtime(true);
            try { $slow->getEndpoint('https://synthetic.example/up', [], deadline: $start + 10_000_000); }
            catch (HttpFailure $error) { $reason = $error->reason; }
            Assert::same($reason, 'timeout'); Assert::same($slow->calls, 1);
            Assert::true(hrtime(true) - $start > 10_000_000, 'synchronous resolver overrun is qualified, not a hard DNS bound');
        } finally { $server->close(); }
    }

    #[Test]
    public function realTransfersKeepTheOriginalAbsoluteDeadline(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 12);
            return;
        }
        $server = new RedirectServer();
        try {
            \Tablo\Tests\Support\SharedDeadlineProbe::verify($server->base);
            Assert::same(count($server->rows()), 2, 'both native transfers actually reach the peer');
            Assert::same(array_column($server->rows(), 'bearer'), [true, true]);
        } finally { $server->close(); }
    }

    #[Test]
    public function expiredDeadlineRefusesBeforeAnyIo(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 6);
            return;
        }
        $server = new RedirectServer();
        try {
            \Tablo\Tests\Support\RedirectAssertions::expired($server);
        } finally { $server->close(); }
    }

    #[Test]
    public function observedNativeResponseExpiresInFlight(): void
    {
        if (getenv('TABLO_TEST_ISOLATED_TRACE') !== __FUNCTION__) {
            \Tablo\Tests\Support\TraceTestIsolation::run(__FILE__, __FUNCTION__, minimumAssertions: 10);
            return;
        }
        $server = new RedirectServer();
        try { \Tablo\Tests\Support\HeldResponseProbe::verify($server); }
        finally { $server->close(); }
    }

}
