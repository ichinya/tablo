<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use PDO;
use Tablo\DatabaseConfiguration;
use Tablo\DatabaseConnectionFactory;
use Tablo\DatabaseFailure;
use Tablo\ServerSchema;
use Tablo\SharedKeyFailure;
use Tablo\TokenVault;
use Tablo\WriteTransaction;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\FixtureCredentials;
use Testo\Assert;
use Testo\Test;

final class EngineKeyTest
{
    #[Test]
    public function verifierStillRejectsWrongKeyAfterEveryCipherWasDeleted(): void
    {
        $fixture = new EngineFixture('key-verifier');
        $db = $fixture->database(); self::seed($db, $fixture->vault);
        $db->exec('UPDATE sites SET github_token = NULL, endpoint_token = NULL');
        $db->exec('DELETE FROM git_tokens');
        $db->exec('UPDATE notification_settings SET endpoint_cipher = NULL, bearer_cipher = NULL');
        $db->exec('UPDATE notification_slots SET private_cipher = NULL');
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM github_cooldowns'), 1);
        $wrong = EngineSchedule::directory() . '/cases/key-verifier/wrong.bin';
        file_put_contents($wrong, random_bytes(32)); chmod($wrong, permissions: 0o600);
        $caught = null;
        try { DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), vault: new TokenVault($wrong, external: true)); }
        catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'key');
        Assert::same($caught?->getPrevious(), null);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM users'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM github_cooldowns'), 1);
        $fixture->independent();
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM tablo_database_state'), 1);
        $fixture->close();
    }

    #[Test]
    public function oneCorruptCipherKeepsOtherOwnersUsableAndAllCorruptStillRefuses(): void
    {
        $fixture = new EngineFixture('key-corruption');
        $db = $fixture->database(); self::seed($db, $fixture->vault);
        $db->exec("UPDATE git_tokens SET encrypted_token = 'v1:bad'");
        $accepted = $fixture->independent();
        $rows = ServerSchema::rows($accepted, 'SELECT endpoint_token FROM sites');
        Assert::same($fixture->vault->decrypt(\Tablo\SqlColumn::string($rows[0] ?? [], 'endpoint_token')), FixtureCredentials::value(label: 'six-owner-cipher'));
        $db->exec("UPDATE sites SET github_token = 'v1:bad', endpoint_token = 'v1:bad'");
        $db->exec("UPDATE notification_settings SET endpoint_cipher = 'v1:bad', bearer_cipher = 'v1:bad'");
        $db->exec("UPDATE notification_slots SET private_cipher = 'v1:bad'");
        $caught = null;
        try { $fixture->independent(); } catch (SharedKeyFailure $error) { $caught = $error; }
        Assert::same($caught?->getCode(), SharedKeyFailure::UNVERIFIED);
        Assert::same($caught?->getMessage(), SharedKeyFailure::UNVERIFIED_DIAGNOSTIC);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 1);
        $fixture->close();
    }

    #[Test]
    public function changedLifetimeKeyRefusesBeforeAnExistingWriterCanMutate(): void
    {
        $fixture = new EngineFixture('key-changed');
        $path = EngineSchedule::directory() . '/cases/key-changed/copy.bin';
        copy(EngineSchedule::directory() . '/private/installation.bin', $path); chmod($path, permissions: 0o600);
        $vault = new TokenVault($path, external: true);
        $db = DatabaseConnectionFactory::open(DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), vault: $vault);
        file_put_contents($path, random_bytes(32));
        $caught = null;
        try { WriteTransaction::run($db, static fn (): int|false => $db->exec('UPDATE installation_settings SET check_interval_minutes = 99')); }
        catch (SharedKeyFailure $error) { $caught = $error; }
        Assert::same($caught?->getMessage(), 'Token key custody changed.');
        Assert::false($db->inTransaction());
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings'), 10);
        $fixture->close();
    }

    private static function seed(PDO $db, TokenVault $vault): void
    {
        $cipher = $vault->encrypt(FixtureCredentials::value(label: 'six-owner-cipher'));
        WriteTransaction::run($db, static function () use ($db, $vault, $cipher): void {
            ServerSchema::rows($db, "INSERT INTO git_tokens (id,name,provider,encrypted_token,created_at,updated_at) VALUES (7,'Synthetic','github',?,'2000-01-01T00:00:00Z','2000-01-01T00:00:00Z')", [$cipher]);
            ServerSchema::rows($db, "INSERT INTO sites (id,name,url,repository,github_token,endpoint_token,created_at,updated_at) VALUES (20,'Synthetic','https://synthetic.invalid','synthetic/repository',?,?,'2000-01-01T00:00:00Z','2000-01-01T00:00:00Z')", [$cipher, $cipher]);
            ServerSchema::rows($db, 'UPDATE notification_settings SET endpoint_cipher = ?, bearer_cipher = ?', [$cipher, $cipher]);
            ServerSchema::rows($db, "INSERT INTO notification_slots (site_id,event,event_id,source_id,config_revision,channel_revision,payload,private_cipher,created_at,expires_at,due_at,status,attempts) VALUES (20,'unavailable','synthetic-key-event',1,0,0,'{}',?,1,2,1,'sent',1)", [$cipher]);
            ServerSchema::rows($db, "INSERT INTO github_cooldowns (scope,resource,eligible_at) VALUES (?,'core',1)", [$vault->credentialScope(FixtureCredentials::value(label: 'six-owner-cipher'))]);
            $db->exec('UPDATE tablo_database_state SET committed_history_high_watermark = 1 WHERE id = 1');
        });
    }
}
