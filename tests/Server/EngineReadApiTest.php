<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\ReadApiRepository;
use Tablo\ReadApiSchemaAdmission;
use Tablo\ReadApiTokenRepository;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\ReadApiAssertions;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class EngineReadApiTest
{
    #[Test]
    public function bearerHttpUsesSelectedReaderWithoutKeyCookieOrPrivateColumns(): void
    {
        $engine = new EngineFixture('api-key-free');
        $fixture = new ReadApiFixture($engine->environment() + ['TABLO_TEST_READ_API_KEY_FREE' => '1']);
        try {
            $secret = $fixture->rotate($fixture->web->authenticate());
            $cipher = $engine->vault->encrypt(FixtureCredentials::value(label: 'api-private'));
            ReadApiAssertions::insert($engine->database(), replace: ['github_token' => $cipher, 'endpoint_token' => $cipher]);
            $sessions = glob($fixture->web->directory->path . '/runtime/sessions/sess_*');
            if ($sessions === false) { throw new \RuntimeException('Owned session inventory failed.'); }
            $before = array_map(\Tablo\RequiredFile::digest(...), $sessions);
            ReadApiFixture::error($fixture->api(), 401, 'unauthorized');
            $response = $fixture->api(secret: $secret);
            Assert::same($response['status'], 200);
            ReadApiFixture::headers($response);
            $page = ReadApiAssertions::page($response['body']);
            Assert::same(count($page['data']), 1);
            $dto = $page['data'][0] ?? throw new \RuntimeException('Expected native API row missing.');
            Assert::same($dto['id'], '1');
            Assert::same($dto['origin'], 'https://example.test');
            Assert::false(str_contains($response['body'], $cipher));
            Assert::false(str_contains($response['body'], $secret));
            Assert::same(array_map(\Tablo\RequiredFile::digest(...), $sessions), $before);
            Assert::false(is_file($fixture->web->directory->path . '/test.sqlite'));
            Assert::same($engine->scalar('SELECT COUNT(*) FROM sites'), 1);
        } finally { $fixture->close(); $engine->close(); }
    }

    #[Test]
    public function admittedReadSnapshotKeepsAuthenticationDataAndMetadataAcrossRevocation(): void
    {
        $fixture = new EngineFixture('api-snapshot');
        $writer = $fixture->database();
        $password = \Tablo\Tests\Support\FixtureCredentials::syntheticPassword();
        (new \Tablo\Auth($writer))->setup($password, $password);
        ReadApiAssertions::insert($writer);
        $tokens = new ReadApiTokenRepository($writer);
        $fingerprint = ReadApiTokenRepository::administrator($writer);
        Assert::notNull($fingerprint);
        $secret = $tokens->rotate($fingerprint, static fn (): bool => true);
        $reader = $fixture->independent('reader');
        $reader->exec('SET TRANSACTION READ ONLY'); $reader->beginTransaction();
        try {
            ReadApiSchemaAdmission::authentication($reader);
            Assert::true((new ReadApiTokenRepository($reader))->authenticate($secret));
            $metadata = (new ReadApiTokenRepository($reader))->metadata();
            $state = ServerSchema::rows($reader, 'SELECT logical_version,phase FROM tablo_database_state');
            $tokens->revoke($fingerprint, static fn (): bool => true);
            ReadApiSchemaAdmission::sites($reader);
            Assert::same(count((new ReadApiRepository($reader))->page()), 1);
            Assert::same((new ReadApiTokenRepository($reader))->metadata(), $metadata);
            Assert::same(ServerSchema::rows($reader, 'SELECT logical_version,phase FROM tablo_database_state'), $state);
        } finally { $reader->rollBack(); }
        Assert::false((new ReadApiTokenRepository($reader))->authenticate($secret));
        Assert::same((new ReadApiTokenRepository($reader))->metadata()['active'], false);
        $fixture->close();
    }

    #[Test]
    public function actualReaderPrivilegesRefuseWritesTemporaryDdlAndSecretColumns(): void
    {
        $fixture = new EngineFixture('api-grants');
        $reader = $fixture->independent('reader');
        ReadApiSchemaAdmission::authentication($reader); ReadApiSchemaAdmission::sites($reader);
        Assert::same(ServerSchema::rows($reader, 'SELECT id,password_hash FROM users'), []);
        Assert::same(ServerSchema::rows($reader, 'SELECT * FROM read_api_token'), []);
        foreach (['UPDATE users SET password_hash = \'synthetic\' WHERE id = 1', 'DELETE FROM sites',
            'CREATE TABLE forbidden_reader_table (id BIGINT)', 'CREATE TEMPORARY TABLE forbidden_reader_temporary (id BIGINT)',
            'SELECT github_token FROM sites', 'SELECT key_verifier FROM tablo_database_state'] as $sql) {
            $code = null;
            try { ServerSchema::rows($reader, $sql); } catch (\PDOException $error) { $code = \Tablo\Tests\Support\FixtureValue::integer($error->errorInfo[1] ?? null); }
            Assert::true(is_int($code), 'A real native privilege refusal is required.');
            Assert::true(in_array($code, [1142, 1143], strict: true));
        }
        Assert::same(count(ServerSchema::objects($fixture->database())), 18);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 0);
        $fixture->close();
    }

    #[Test]
    public function nonReadyCatalogCannotServeBearerDataAndEarlyUnauthorizedStaysFixed(): void
    {
        $engine = new EngineFixture('api-migration');
        $fixture = new ReadApiFixture($engine->environment());
        try {
            $secret = $fixture->rotate($fixture->web->authenticate());
            Assert::same($fixture->api(secret: $secret)['status'], 200);
            $maintenance = $engine->maintenance();
            $maintenance->exec("UPDATE tablo_database_state SET phase = 'migrating' WHERE id = 1");
            ReadApiFixture::error($fixture->api(secret: $secret), 500, 'unavailable');
            ReadApiFixture::error($fixture->api(), 401, 'unauthorized');
            Assert::same(ServerSchema::rows($maintenance, 'SELECT phase FROM tablo_database_state'), [['phase' => 'migrating']]);
            Assert::same(count(ServerSchema::objects($maintenance)), 18);
        } finally { $fixture->close(); $engine->close(); }
    }
}
