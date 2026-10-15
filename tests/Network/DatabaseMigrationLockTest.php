<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\Tests\Support\MigrationProcess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class DatabaseMigrationLockTest extends DatabaseMigrationFixtureCase
{


    #[Test]
    public function connectsAndReadsCurrentSchemaWhileAnotherProcessHoldsWriterLock(): void
    {
        $directory = new TemporaryDirectory('tablo-schema-read-');
        $reader = null; $writer = null;
        try {
            $path = $directory->path . '/test.sqlite';
            $db = Database::connect($path);
            $db->exec("INSERT INTO users (id,password_hash) VALUES (1,'synthetic-hash')");
            unset($db);
            $writer = new MigrationProcess($directory, 'writer', 'writer', $path);
            $writer->awaitSignal('writer.locked');
            $reader = new MigrationProcess($directory, 'reader', 'connect', $path);
            $reader->awaitSignal('reader.ready');
            file_put_contents($directory->path . '/start', data: 'start');
            // The reader must finish before the writer is released, not merely within a timing threshold.
            $result = self::result($reader);
            Assert::same($result['version'], Database::CURRENT_SCHEMA_VERSION);
            Assert::same(\Tablo\SqlColumn::integer($result, 'users'), 1);
            file_put_contents($directory->path . '/writer.release', data: 'release');
            self::result($writer);
        } finally {
            $reader?->close();
            $writer?->close();
            unset($db);
            $directory->close();
        }
    }



    #[Test]
    public function rechecksVersionAfterWaitingForAnotherMigrator(): void
    {
        foreach (['fresh', 'legacy', 'version-one'] as $schema) {
            $legacy = $schema !== 'fresh';
            $directory = new TemporaryDirectory('tablo-schema-race-');
            $second = null; $first = null;
            try {
                $path = $directory->path . '/test.sqlite';
                if ($legacy) { self::legacy($path, $schema === 'version-one' ? \Tablo\Tests\Support\SchemaFixture::VersionOne : \Tablo\Tests\Support\SchemaFixture::Historic); }
                $first = new MigrationProcess($directory, 'first', 'migrate-first', $path);
                $first->awaitSignal('first.locked');
                $second = new MigrationProcess($directory, 'second', 'migrate-second', $path);
                $second->awaitSignal('second.before-begin');
                file_put_contents($directory->path . '/first.release', data: 'release');
                $a = self::result($first);
                $b = self::result($second);
                Assert::same($a['version'], Database::CURRENT_SCHEMA_VERSION);
                Assert::same($b['version'], Database::CURRENT_SCHEMA_VERSION);
                Assert::true(\Tablo\Tests\Support\FixtureValue::integer($a['ddl']) > 0);
                Assert::same($b['ddl'], 0, 'Waiting migrator executed bootstrap after the version changed');
                $db = Database::connect($path);
                Assert::same((int) \Tablo\SqlStatement::checked($db->query('SELECT count(*) FROM users'))->fetchColumn(), $legacy ? 1 : 0);
                if ($legacy) {
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT password_hash FROM users'))->fetchColumn(), 'synthetic-hash');
                    Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT github_token FROM sites'))->fetchColumn(), 'synthetic-ciphertext');
                }
            } finally {
                $second?->close();
                $first?->close();
                unset($db);
                $directory->close();
            }
        }
    }



    #[Test]
    public function supportsSimultaneousRealConnectCallsOnFreshAndLegacyFiles(): void
    {
        foreach (['fresh', 'legacy', 'version-one'] as $schema) {
            $legacy = $schema !== 'fresh';
            $directory = new TemporaryDirectory('tablo-schema-start-');
            $second = null; $first = null;
            try {
                $path = $directory->path . '/test.sqlite';
                if ($legacy) { self::legacy($path, $schema === 'version-one' ? \Tablo\Tests\Support\SchemaFixture::VersionOne : \Tablo\Tests\Support\SchemaFixture::Historic); }
                $first = new MigrationProcess($directory, 'first', 'connect', $path);
                $second = new MigrationProcess($directory, 'second', 'connect', $path);
                $first->awaitSignal('first.ready');
                $second->awaitSignal('second.ready');
                file_put_contents($directory->path . '/start', data: 'start');
                foreach ([self::result($first), self::result($second)] as $result) {
                    Assert::same($result['version'], Database::CURRENT_SCHEMA_VERSION);
                    Assert::same(\Tablo\SqlColumn::integer($result, 'users'), $legacy ? 1 : 0);
                }
                $db = Database::connect($path);
                Assert::same(\Tablo\SqlStatement::checked($db->query('PRAGMA integrity_check'))->fetchColumn(), 'ok');
            } finally {
                $second?->close();
                $first?->close();
                unset($db);
                $directory->close();
            }
        }
    }
}
