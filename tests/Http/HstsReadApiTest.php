<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\ReadApiTokenRepository;
use Tablo\Tests\Support\HstsFixture;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class HstsReadApiTest
{
    #[Test]
    public function finalSenderRetainsExactlyOneValidatedFieldAcrossApiStatusesWithoutWrites(): void
    {
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1']);
        $db = null; $tokens = null;
        try {
            $fixture->authenticate(); $db = \Tablo\Database::connect($fixture->source . '/storage/fixture.sqlite');
            $fingerprint = ReadApiTokenRepository::administrator($db); Assert::notNull($fingerprint);
            $tokens = new ReadApiTokenRepository($db); $secret = $tokens->rotate($fingerprint, static fn (): bool => true);
            $before = A::snapshot($db); $headers = ['X-Forwarded-Proto: https', 'Authorization: Bearer ' . $secret];
            foreach ([['/api/v1/sites', 'GET', 200], ['/api/v1/sites?limit=0', 'GET', 400], ['/api/v1/sites/999', 'GET', 404], ['/api/v1/sites', 'POST', 405]] as [$path, $method, $status]) {
                $response = $fixture->request($path, headers: $headers, method: $method); Assert::same($response['status'], $status); ReadApiFixture::headers($response); HstsFixture::header($response, 'max-age=300');
                Assert::same(A::snapshot($db), $before);
            }
            $early = $fixture->request('/api/v1/sites', headers: ['X-Forwarded-Proto: https']); ReadApiFixture::error($early, 401, 'unauthorized'); HstsFixture::header($early, 'max-age=300');
            $tokens->revoke($fingerprint, static fn (): bool => true);
            $revoked = $fixture->request('/api/v1/sites', headers: $headers); ReadApiFixture::error($revoked, 401, 'unauthorized'); HstsFixture::header($revoked, 'max-age=300');
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { unset($tokens, $db); $fixture->close(); }
    }

    #[Test]
    public function hostileCapturedWorkCannotAuthorizeHeadersCookiesLocationCorsOutputOrStatus(): void
    {
        foreach (['absent', 'output', 'headers', 'status', 'closed', 'buffers', 'throw'] as $fault) {
            $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'], $fault);
            try {
                $response = $fixture->request('/api/v1/sites', headers: ['X-Forwarded-Proto: https', 'Authorization: Bearer ' . ReadApiFixture::wrong()]);
                if ($fault === 'buffers') {
                    // Preserve the existing refusal to send through an unclean capture.
                    Assert::same($response['status'], 500); Assert::same($response['body'], ''); HstsFixture::header($response, null);
                }
                if ($fault !== 'buffers') { ReadApiFixture::error($response, 500, 'unavailable'); HstsFixture::header($response, 'max-age=300'); }
                Assert::false(str_contains($response['body'], 'private-hsts-canary')); Assert::false(is_dir($fixture->source . '/storage'));
            } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
            finally { $fixture->close(); }
        }
        $fixture = new HstsFixture(['TABLO_HSTS_MODE' => 'proxy', 'TABLO_HSTS_MAX_AGE' => '300', 'TABLO_TRUSTED_PROXIES' => '127.0.0.1'], autoload: 'missing-api');
        try {
            $fallback = $fixture->request('/api/v1/sites', headers: ['X-Forwarded-Proto: https']);
            ReadApiFixture::error($fallback, 500, 'unavailable'); HstsFixture::header($fallback, 'max-age=300');
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
