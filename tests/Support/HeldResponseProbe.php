<?php
declare(strict_types=1);

namespace Tablo\Tests\Support;

use Tablo\CurlGetFailure;
use Tablo\CurlGetOptions;
use Tablo\HttpFailure;
use Tablo\HttpResponseBuffer;
use Testo\Assert;

/** Arm the in-flight control only after the native receiver has started its held response. */
final class HeldResponseProbe
{
    public static function verify(RedirectServer $server): void
    {
        $chain = null;
        $buffer = new HttpResponseBuffer($chain);
        $curl = curl_init($server->base . '/held/observed');
        $multi = curl_multi_init();
        $old = \Tablo\RuntimeIni::set('zend.exception_ignore_args', value: '0');
        try {
            // Setup has its own finite bound; the caller-created deadline is exercised separately.
            $options = CurlGetOptions::build(RedirectAssertions::headers(), [], 2_000, $buffer, null);
            Assert::true(curl_setopt_array($curl, $options));
            $transferStarted = \Tablo\MonotonicTime::integer(hrtime(true));
            Assert::same(curl_multi_add_handle($multi, $curl), CURLM_OK);
            $setupDeadline = \Tablo\MonotonicTime::integer(hrtime(true)) + 2_000_000_000;
            do {
                $active = 0;
                if (curl_multi_exec($multi, $active) !== CURLM_OK) { throw new \RuntimeException('Native held-response setup failed'); }
                if ($buffer->body === 'x') { break; }
                if ($active === 0 || \Tablo\MonotonicTime::integer(hrtime(true)) >= $setupDeadline) { throw new \RuntimeException('Native held-response setup did not complete'); }
                usleep(1_000);
            } while (true);
            Assert::same($buffer->body, 'x', 'native response body was received before timing the held remainder');
            Assert::same(count($server->rows()), 1, 'authenticated request actually reached the peer');
            Assert::same(\Tablo\Tests\Support\FixtureValue::at($server->rows(), 0)['route'], 'held');
            Assert::true(\Tablo\Tests\Support\FixtureValue::at($server->rows(), 0)['bearer']);
            $start = \Tablo\MonotonicTime::integer(hrtime(true));
            // libcurl measures TIMEOUT_MS from transfer start, including the completed setup interval.
            $elapsedMs = (int) ceil(($start - $transferStarted) / 1_000_000);
            Assert::true(curl_setopt($curl, CURLOPT_TIMEOUT_MS, $elapsedMs + 180));
            self::finish($multi, $start);
            $info = curl_multi_info_read($multi);
            Assert::same($info['result'], CURLE_OPERATION_TIMEDOUT, 'native transfer itself expired while the response was held');
            Assert::same(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 200, 'native timeout occurs after receiving the held peer response');
            Assert::true((\Tablo\MonotonicTime::integer(hrtime(true)) - $start) / 1e6 < 900);
            try { CurlGetFailure::fail($curl, $buffer, $chain); }
            catch (HttpFailure $error) {
                Assert::same($error->reason, 'timeout');
                Assert::same($error->getPrevious(), null);
                Assert::false(TraceInspector::inspect($error, ['synthetic-redirect-token'])['leaked']);
            }
            Assert::same(count($server->rows()), 1, 'held in-flight control cannot advance to another request');
        } finally {
            curl_multi_remove_handle($multi, $curl);
            unset($options, $curl, $multi);
            \Tablo\RuntimeIni::set('zend.exception_ignore_args', $old);
        }
    }

    private static function finish(\CurlMultiHandle $multi, int $start): void
    {
        do {
            $active = 0;
            if (curl_multi_exec($multi, $active) !== CURLM_OK) { throw new \RuntimeException('Native held-response loop failed'); }
            if ($active === 0) { return; }
            if ((\Tablo\MonotonicTime::integer(hrtime(true)) - $start) / 1e6 >= 900) { throw new \RuntimeException('Held response exceeded the original upper bound'); }
            usleep(1_000);
        } while (true);
    }
}
