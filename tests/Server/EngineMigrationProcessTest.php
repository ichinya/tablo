<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\DatabaseFailure;
use Tablo\DatabaseInteger;
use Tablo\JsonRecord;
use Tablo\RequiredFile;
use Tablo\ServerMigration;
use Tablo\ServerSchema;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineProcess;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

/** @phpstan-import-type Result from Subprocess */
final class EngineMigrationProcessTest
{
    #[Test]
    public function twoMigrationProcessesContendAcrossDdlWhilePartialWriterAndReaderRemainRefused(): void
    {
        $fixture = new EngineFixture('migration-two-processes');
        $directory = new TemporaryDirectory('tablo-server-migration-'); $children = []; $primary = null;
        try {
            $started = hrtime(true) / 1_000_000_000; $deadline = $started + 7;
            $owner = EngineProcess::migration($fixture, $directory, 'migration-owner'); $children[] = $owner;
            $contender = EngineProcess::migration($fixture, $directory, 'migration-contender'); $children[] = $contender;
            foreach ($children as $child) { $child->awaitOutput("ENGINE_MIGRATION_READY\n", self::remaining($started + 2)); }
            $owner->sendBarrier("go\n"); $owner->awaitOutput("ENGINE_MIGRATION_HELD\n", self::remaining($deadline));
            $contender->sendBarrier("go\n"); $contender->awaitOutput("ENGINE_MIGRATION_CONTENDED\n", self::remaining($deadline));
            $observer = $fixture->maintenance();
            Assert::same(ServerSchema::objects($observer), ['tablo_database_state', 'tablo_migration_journal', 'tablo_writer_guard']);
            $receipt = EngineSchedule::receipt($fixture->case); $receiptDigest = RequiredFile::digest($receipt);
            foreach (['writer', 'reader'] as $role) {
                $failure = null;
                try { $fixture->independent($role); } catch (DatabaseFailure $error) { $failure = $error; }
                Assert::same($failure?->category, 'schema'); Assert::same($failure?->getPrevious(), null);
            }
            $owner->sendBarrier("continue\n");
            $ownerId = self::result($owner->finish(self::remaining($deadline)), "ENGINE_MIGRATION_READY\nENGINE_MIGRATION_HELD\n");
            $contenderId = self::result($contender->finish(self::remaining($deadline)), "ENGINE_MIGRATION_READY\nENGINE_MIGRATION_CONTENDED\n");
            Assert::true($ownerId !== $contenderId);
            Assert::same(RequiredFile::digest($receipt), $receiptDigest);
            Assert::same(count(ServerSchema::objects($observer)), 19);
            Assert::same($fixture->scalar("SELECT COUNT(*) FROM tablo_migration_journal WHERE phase = 'done'", $observer), 19);
            Assert::same((new ServerMigration($observer, $fixture->vault))->status(), ['format' => 1, 'logical_version' => 11, 'phase' => 'ready']);
        } catch (\Throwable $error) {
            $primary = $error; $directory->retainFailure($error); throw $error;
        } finally {
            foreach (array_reverse($children) as $child) { $directory->cleanup(static fn () => $child->close($primary)); }
            $directory->cleanup($fixture->close(...)); $directory->cleanup($directory->close(...));
        }
    }

    private static function remaining(float $deadline): float
    {
        $seconds = $deadline - hrtime(true) / 1_000_000_000;
        if ($seconds <= 0) { throw new \RuntimeException('Migration process schedule exceeded its fixed case budget.'); }
        return min(6, $seconds);
    }

    /** @param Result $result */
    private static function result(array $result, string $prefix): int
    {
        Assert::same($result['exit_code'], 0); Assert::same($result['stderr'], '');
        Assert::true(str_starts_with($result['stdout'], $prefix));
        $record = JsonRecord::decode(substr($result['stdout'], offset: strlen($prefix)));
        Assert::same([$record['status'] ?? null, $record['phase'] ?? null, $record['version'] ?? null], ['passed', 'ready', 10]);
        return DatabaseInteger::positive($record['connectionId'] ?? null);
    }
}
