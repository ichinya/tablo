<?php
declare(strict_types=1);

namespace Tablo\Tests\ServerCross;

use Tablo\DatabaseTransfer;
use Tablo\DatabaseTransferManifest;
use Tablo\DatabaseTransferSequence;
use Tablo\ServerSchema;
use Tablo\Tests\Server\EngineTransferTest;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\EngineSchedule;
use Tablo\Tests\Support\FixtureCredentials;
use Testo\Assert;
use Testo\Test;

/** Mandatory MySQL/PHP8.5 sublane; both engines are explicit real, independently provisioned services. */
final class EngineCrossTransferTest
{
    #[Test]
    public function mysqlToMariadbPreservesEveryCurrentTableAndSequence(): void
    {
        $source = new EngineFixture('cross-mysql-source');
        $target = new EngineFixture('cross-mariadb-target', peerEngine: 'mariadb');
        self::transfer($source, $target);
    }

    #[Test]
    public function mariadbToMysqlPreservesEveryCurrentTableAndSequence(): void
    {
        $source = new EngineFixture('cross-mariadb-source', peerEngine: 'mariadb');
        $target = new EngineFixture('cross-mysql-target');
        self::transfer($source, $target);
    }

    private static function transfer(EngineFixture $source, EngineFixture $target): void
    {
        Assert::same(getenv('TABLO_TEST_DB_DRIVER'), 'mysql');
        Assert::same([PHP_MAJOR_VERSION, PHP_MINOR_VERSION], [8, 5]);
        $engine = \Tablo\SqlColumn::string($source->environment(), 'TABLO_DB_DRIVER');
        $root = EngineSchedule::directory($engine === getenv('TABLO_TEST_DB_DRIVER') ? null : $engine) . '/cases/' . $source->case;
        $credential = FixtureCredentials::value(label: 'transfer-token');
        Assert::same($source->vault->credentialScope($credential), $target->vault->credentialScope($credential));
        $sqlite = EngineTransferTest::source($root . '/seed.sqlite', $source->vault);
        $seedDigest = DatabaseTransfer::export($sqlite, $source->vault, $root . '/seed', $root . '/seed-scratch.sqlite', offline: true);
        Assert::same(DatabaseTransfer::importServer($source->maintenance(), $source->vault, $root . '/seed', $root . '/source-import.sqlite', offline: true), $seedDigest);
        $db = $source->database();
        $digest = DatabaseTransfer::export($source->independent(), $source->vault, $root . '/archive', $root . '/export.sqlite', offline: true);
        Assert::same(DatabaseTransfer::importServer($target->maintenance(), $target->vault, $root . '/archive', $root . '/target-import.sqlite', offline: true), $digest);
        $other = $target->database();
        Assert::same(DatabaseTransfer::verify($other, $target->vault, $root . '/archive', $root . '/target-verify.sqlite'), $digest);
        // Compare against the original independently materialized SQLite rows as well as the cross archive.
        Assert::same(DatabaseTransfer::verify($other, $target->vault, $root . '/seed', $root . '/seed-verify.sqlite'), $seedDigest);
        Assert::same(count(DatabaseTransferManifest::TABLES), 15);
        foreach (DatabaseTransferManifest::TABLES as $table) {
            Assert::true(DatabaseTransferSequence::scalar($other, 'SELECT COUNT(*) AS value FROM `' . $table . '`') > 0, $table);
        }
        Assert::same(DatabaseTransferSequence::floors($other), DatabaseTransferSequence::floors($db));
        Assert::same(DatabaseTransferSequence::watermark($other), 9000);
        Assert::same(ServerSchema::rows($other, 'SELECT run_id,stop_requested,fairness_turn FROM worker_runtime'),
            [['run_id' => null, 'stop_requested' => 1, 'fairness_turn' => 3]]);
        $source->close(); $target->close();
    }
}
