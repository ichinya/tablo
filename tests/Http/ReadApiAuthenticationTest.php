<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiAuthenticationTest
{
    #[Test]
    public function oldFutureAndBrokenResourceSchemaReturnFixedFailuresWithoutMigrationOrResourceDisclosure(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
        try {
            $secret = $fixture->rotate($web->authenticate()); $db = $web->database();
            foreach ([\Tablo\Database::CURRENT_SCHEMA_VERSION - 1, \Tablo\Database::CURRENT_SCHEMA_VERSION + 1] as $version) {
                $db->exec('PRAGMA user_version=' . $version); $before = A::snapshot($db);
                ReadApiFixture::error($fixture->api(secret: $secret), 500, 'unavailable'); Assert::same(A::snapshot($db), $before);
            }
            $db->exec('PRAGMA user_version=' . \Tablo\Database::CURRENT_SCHEMA_VERSION);
            $db->exec('DROP TABLE sites'); $before = A::snapshot($db);
            ReadApiFixture::error($fixture->api(secret: ReadApiFixture::wrong()), 401, 'unauthorized');
            ReadApiFixture::error($fixture->api(secret: $secret), 500, 'unavailable'); Assert::same(A::snapshot($db), $before);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { unset($db); $fixture->close(); }
    }

    #[Test]
    public function missingMalformedWrongAndCookieOnlyCredentialsCannotDiscloseResourcesOrCreateAbsentDatabase(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web;
        try {
            ReadApiFixture::error($fixture->api(), 401, 'unauthorized'); Assert::false(is_file($web->directory->path . '/test.sqlite'));
            ReadApiFixture::error($fixture->api(options: ['headers' => ['Authorization: Basic malformed']]), 401, 'unauthorized'); Assert::false(is_file($web->directory->path . '/test.sqlite'));
            $csrf = $web->authenticate(); $secret = $fixture->rotate($csrf); $before = A::snapshot($web->database());
            foreach (['/api/v1/sites','/api/v1/sites/1','/api/v1/sites/999','/api/v1/sites?invalid=1','/api/v99/sites'] as $path) {
                $response = $fixture->api($path, ReadApiFixture::wrong()); ReadApiFixture::error($response, 401, 'unauthorized');
                Assert::true(str_contains($response['headers'], 'WWW-Authenticate: Bearer realm="tablo-read-api"'));
            }
            ReadApiFixture::error($fixture->api(options: ['cookies' => \Tablo\Tests\Support\CookiePolicy::Retained]), 401, 'unauthorized');
            ReadApiFixture::error($fixture->api(options: ['headers' => ['Authorization: Bearer ' . $secret . ', Bearer ' . $secret]]), 401, 'unauthorized');
            Assert::same(A::snapshot($web->database()), $before);
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { $fixture->close(); }
    }
}
