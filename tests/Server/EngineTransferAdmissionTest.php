<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferVerification;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\Tests\Support\EngineFixture;
use Testo\Assert;
use Testo\Test;

final class EngineTransferAdmissionTest
{
    #[Test]
    public function invalidUtcCodecAlwaysReturnsAFreshSanitizedSchemaFailure(): void
    { engine_transfer_invalid_utc_codec(); }

    #[Test]
    public function unknownHeaderKeysAndNoncanonicalManifestBytesRefuseBelowTheSizeLimit(): void
    {
        $fixture = new EngineFixture('transfer-manifest-shape'); $directory = EngineTransferEnvelopeTest::archive($fixture);
        $original = RequiredFile::bytes(file_get_contents($directory . '/manifest.json'));
        $envelope = JsonRecord::decode($original); $header = JsonRecord::checked($envelope['payload'] ?? null);
        $header['unexpected'] = 'bounded'; EngineTransferEnvelopeTest::manifest($directory, $header);
        $caught = null;
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema');
        $wire = ' ' . $original; Assert::true(strlen($wire) < DatabaseTransferManifest::MANIFEST_BYTES);
        Assert::same(file_put_contents($directory . '/manifest.json', $wire), strlen($wire));
        $failure = null;
        try { DatabaseTransferVerification::manifest($directory, $fixture->vault); } catch (DatabaseFailure $error) { $failure = $error; }
        Assert::same($failure?->category, 'schema'); $fixture->close();
    }

    #[Test]
    public function explicitTransferSourceIsReadonlyAndKeepsTheNormalWriterIndependent(): void
    {
        $fixture = new EngineFixture('transfer-readonly-source');
        $writer = $fixture->database(); $before = $fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer);
        $source = \Tablo\DatabaseConnectionFactory::open(\Tablo\DatabaseConfiguration::fromEnvironment(environment: $fixture->environment()), mode: 'transfer-source', vault: $fixture->vault);
        $bufferAttribute = \Tablo\DatabaseInteger::checked(constant((PHP_VERSION_ID >= 80_400 ? 'Pdo\\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_') . 'USE_BUFFERED_QUERY'));
        Assert::true(in_array($source->getAttribute($bufferAttribute), [false, 0], strict: true));
        $source->beginTransaction(); $native = null;
        try {
            try { $source->exec('UPDATE installation_settings SET check_interval_minutes = 99'); }
            catch (\PDOException $error) { $native = \Tablo\DatabaseInteger::checked($error->errorInfo[1] ?? null); }
            Assert::same($native, 1792);
        } finally { $source->rollBack(); }
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer), $before);
        \Tablo\WriteTransaction::run($writer, static fn (): int|false => $writer->exec('UPDATE installation_settings SET check_interval_minutes = 17'));
        Assert::same($fixture->scalar('SELECT check_interval_minutes FROM installation_settings', $writer), 17);
        $fixture->close();
    }

    #[Test]
    public function binaryCellsPreserveOpaqueBytesWhileUtf8AndUtcRemainValidated(): void
    {
        $table = self::table('login_limits');
        Assert::same($table['name'], 'login_limits');
        $column = DatabaseTransferManifest::columns($table)[0] ?? throw new \RuntimeException('Fixed binary primary key absent.');
        Assert::same($column['type'], 'varbinary(128)');
        $opaque = "synthetic:\xFF\0A ";
        Assert::same(\Tablo\DatabaseTransferRows::value($opaque, $column), $opaque);
        $values = [$opaque, 1, 2];
        Assert::same(\Tablo\DatabaseTransferRows::decode(\Tablo\DatabaseTransferRows::encode($values), $table), $values);
        $sites = self::table('sites');
        $text = DatabaseTransferManifest::columns($sites)[1] ?? throw new \RuntimeException('Fixed UTF-8 name absent.');
        Assert::same([$sites['name'], $text['name'], $text['type']], ['sites', 'name', 'longtext']);
        $users = self::table('users');
        $utc = DatabaseTransferManifest::columns($users)[2] ?? throw new \RuntimeException('Fixed canonical UTC absent.');
        Assert::same([$utc['name'], $utc['type']], ['created_at', 'varbinary(20)']);
        Assert::same(\Tablo\DatabaseTransferRows::value('0001-01-01T00:00:00Z', $utc), '0001-01-01T00:00:00Z');
        foreach ([[$opaque, $text], [$opaque, $utc], [str_repeat('x', times: 129), $column]] as [$invalid, $shape]) {
            $failure = null;
            try { \Tablo\DatabaseTransferRows::value($invalid, $shape); } catch (DatabaseFailure $error) { $failure = $error; }
            Assert::same($failure?->category, 'schema');
        }
    }

    #[Test]
    public function opaqueBinaryPrimaryKeyRoundTripPreservesNulNonUtf8AndTrailingBytes(): void
    {
        $fixture = new EngineFixture('transfer-binary-primary');
        try {
            $root = \Tablo\Tests\Support\EngineSchedule::directory() . '/cases/' . $fixture->case;
            $source = EngineTransferTest::source($root . '/source.sqlite', $fixture->vault); $opaque = "synthetic:\xFF\0A ";
            \Tablo\ServerSchema::rows($source, 'UPDATE login_limits SET `key` = ?', [$opaque]);
            Assert::same(\Tablo\ServerSchema::rows($source, 'SELECT `key`,typeof(`key`) AS storage FROM login_limits'), [['key' => $opaque, 'storage' => 'text']]);
            $digest = \Tablo\DatabaseTransfer::export($source, $fixture->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
            Assert::same(\Tablo\DatabaseTransfer::importServer($fixture->maintenance(), $fixture->vault, $root . '/archive', $root . '/import.sqlite', offline: true), $digest);
            $db = $fixture->database(); Assert::same(\Tablo\ServerSchema::rows($db, 'SELECT `key` FROM login_limits'), [['key' => $opaque]]);
            $reverse = \Tablo\DatabaseTransfer::export($fixture->independent(), $fixture->vault, $root . '/reverse', $root . '/reverse-export.sqlite', offline: true);
            Assert::same(\Tablo\DatabaseTransfer::importSqlite($fixture->vault, $root . '/reverse', $root . '/staging.sqlite', $root . '/target.sqlite', offline: true), $reverse);
            $target = new \PDO('sqlite:' . $root . '/target.sqlite', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            Assert::same(\Tablo\ServerSchema::rows($target, 'SELECT `key`,typeof(`key`) AS storage FROM login_limits'), [['key' => $opaque, 'storage' => 'text']]);
            Assert::same(\Tablo\DatabaseTransfer::verify($target, $fixture->vault, $root . '/archive', $root . '/verify.sqlite'), $digest);
        } finally { $fixture->close(); }
    }

    /** @return \Tablo\DatabaseTransferManifest::Table */
    private static function table(string $name): array
    {
        foreach (DatabaseTransferManifest::tables() as $table) {
            if ($table['name'] === $name) { return $table; }
        }
        throw new \RuntimeException('Fixed transfer table absent.');
    }
}

function engine_transfer_invalid_utc_codec(): void
{
    $utc = ['name' => 'created_at', 'type' => 'varbinary(20)', 'nullable' => false];
    foreach (['0001-01-01T00:00:00Z', '0099-12-31T23:59:59Z', '0999-12-31T23:59:59Z', '2000-02-29T12:00:00Z'] as $valid) {
        Assert::same(\Tablo\DatabaseTransferRows::value($valid, $utc), $valid);
    }
    foreach ([null, '', 'not-a-timestamp', '0000-01-01T00:00:00Z', '1900-02-29T00:00:00Z',
        '2026-02-30T00:00:00Z', '2026-01-01T00:00:60Z', '2026-01-01T00:00:00+00:00', "synthetic:\xFF\0"] as $invalid) {
        $caught = null;
        try { \Tablo\DatabaseTransferRows::value($invalid, $utc); } catch (DatabaseFailure $error) { $caught = $error; }
        Assert::same($caught?->category, 'schema'); Assert::same($caught?->getPrevious(), null);
    }
}
