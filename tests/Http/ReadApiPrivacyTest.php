<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiPrivacyTest
{
    #[Test]
    public function futureColumnsCredentialsSelectorsDiagnosticsAndRemoteStringsNeverCrossRecursiveWhitelist(): void
    {
        $fixture = new ReadApiFixture(); $web = $fixture->web; $db = null;
        try {
            $secret = $fixture->rotate($web->authenticate()); $db = $web->database();
            $db->exec("ALTER TABLE sites ADD COLUMN future_secret TEXT DEFAULT 'future-secret-canary'");
            A::insert($db, replace: ['github_token' => FixtureCredentials::value('github-cipher-canary'), 'endpoint_token' => FixtureCredentials::value('endpoint-cipher-canary'),
                'health_json_path' => 'selector-canary', 'health_json_expected_value' => 'expected-canary', 'last_error' => 'raw-diagnostic-canary',
                'version_path' => '/private-version-canary', 'health_path' => '/private-health-canary', 'checked_at' => A::NOW,
                'deployed_version' => 'deployed-tag-canary', 'latest_release' => 'latest-tag-canary', 'deployed_commit' => 'commit-canary', 'latest_commit' => 'latest-commit-canary']);
            $response = $fixture->api(secret: $secret); Assert::same($response['status'], 200); Assert::false(str_contains($response['body'], 'canary'));
            Assert::false(str_contains($response['body'], $secret));
            $body = A::page($response['body']);
            foreach ($body['data'] as $site) { A::exactDto($site); }
            $db->exec("UPDATE sites SET checked_at='invalid-calendar-secret-canary'");
            ReadApiFixture::error($fixture->api(secret: $secret), 500, 'unavailable');
            Assert::false(str_contains($web->server->diagnostics(), $secret));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { unset($db); $fixture->close(); }
    }
}
