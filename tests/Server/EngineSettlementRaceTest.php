<?php
declare(strict_types=1);

namespace Tablo\Tests\Server;

use Tablo\GitTokenRepository;
use Tablo\ServerSchema;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\EngineFixture;
use Tablo\Tests\Support\FixtureCredentials;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

/** Independent real sessions reproduce stale completion interleavings; simultaneous process schedules are separate cases. */
final class EngineSettlementRaceTest
{
    #[Test]
    public function identicalCurrentCompletionsBothCommitActualHistoryAndServiceCredit(): void
    {
        $fixture = new EngineFixture('settlement-identical');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $id = $sites->save(UnitFixtures::site());
        $snapshot = FixtureValue::row($sites->find($id));
        $other = $fixture->independent(); $secondSites = new SiteRepository($other, $fixture->vault);
        $state = ['online' => 0, 'checked_at' => '2026-01-01T00:00:00Z', 'worker_service' => ['open_issues' => true]];
        Assert::true((new WorkerStateRepository($db, $sites))->settle($snapshot, $state));
        Assert::true((new WorkerStateRepository($other, $secondSites))->settle($snapshot, $state));
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 2);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incidents WHERE end_reason IS NULL'), 1);
        Assert::same($fixture->scalar('SELECT last_history_id FROM incident_checkpoints'), 2);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 2);
        Assert::same($fixture->scalar('SELECT open_issues FROM worker_progress'), 2);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 2);
        Assert::false($db->inTransaction()); Assert::false($other->inTransaction());
        $fixture->close();
    }

    #[Test]
    public function editBackAndDisableEnableAbaRejectOldCompletionBeforeResultValidation(): void
    {
        $fixture = new EngineFixture('settlement-config-aba');
        $sites = new SiteRepository($fixture->database(), $fixture->vault);
        $other = new SiteRepository($fixture->independent(), $fixture->vault);
        foreach ([['url' => 'https://changed.invalid'], ['enabled' => 0]] as $change) {
            $input = UnitFixtures::site(); $id = $sites->save($input);
            $snapshot = FixtureValue::row($sites->find($id));
            $other->save(array_replace($input, $change), $id); $other->save($input, $id);
            Assert::same(FixtureValue::row($sites->find($id))['config_revision'], 2);
            Assert::false($sites->settleWorkerCheck($snapshot, ['checked_at' => 'invalid', 'worker_service' => ['open_issues' => true]]));
            self::unchangedSettlement($fixture);
        }
        $fixture->close();
    }

    #[Test]
    public function byteCaseAndTrailingSpaceCannotPassTheCurrentConfigurationCas(): void
    {
        $fixture = new EngineFixture('settlement-byte-cas');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $other = $fixture->independent();
        foreach (['tempalog', 'Tempalog '] as $name) {
            $id = $sites->save(UnitFixtures::site()); $snapshot = FixtureValue::row($sites->find($id));
            ServerSchema::rows($other, 'UPDATE sites SET name = ? WHERE id = ?', [$name, $id]);
            Assert::false($sites->storeCheck($snapshot, ['checked_at' => 'invalid']));
            self::unchangedSettlement($fixture);
        }
        $fixture->close();
    }

    #[Test]
    public function nullAndEmptyCredentialBytesRemainDifferentInBothCasDirections(): void
    {
        $fixture = new EngineFixture('settlement-null-cas');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $other = $fixture->independent(); $id = $sites->save(UnitFixtures::site());
        $nullSnapshot = FixtureValue::row($sites->find($id));
        ServerSchema::rows($other, 'UPDATE sites SET endpoint_token = ? WHERE id = ?', ['', $id]);
        Assert::false($sites->storeCheck($nullSnapshot, ['checked_at' => 'invalid']));
        $emptySnapshot = FixtureValue::row($sites->find($id));
        ServerSchema::rows($other, 'UPDATE sites SET endpoint_token = NULL WHERE id = ?', [$id]);
        Assert::false($sites->storeCheck($emptySnapshot, ['checked_at' => 'invalid']));
        self::unchangedSettlement($fixture);
        $fixture->close();
    }

    #[Test]
    public function savedTokenRotationAndReturnCannotReuseAnOldSelectedCipher(): void
    {
        $fixture = new EngineFixture('settlement-token-aba');
        $db = $fixture->database(); $sites = new SiteRepository($db, $fixture->vault);
        $tokens = new GitTokenRepository($fixture->independent(), $fixture->vault);
        $original = ['name' => 'Shared', 'provider' => 'github', 'token' => FixtureCredentials::value(label: 'settlement-original')];
        $tokenId = $tokens->save($original);
        $id = $sites->save(array_replace(UnitFixtures::site(), ['git_token_id' => $tokenId]));
        $snapshot = FixtureValue::row($sites->find($id));
        Assert::same($snapshot['config_revision'], 0);
        $tokens->save(array_replace($original, ['token' => FixtureCredentials::value(label: 'settlement-rotated')]), $tokenId);
        Assert::same(FixtureValue::row($sites->find($id))['config_revision'], 1);
        $tokens->save($original, $tokenId);
        $returned = FixtureValue::row($sites->find($id));
        Assert::same($returned['config_revision'], 2);
        Assert::notSame($returned['selected_token_snapshot'], $snapshot['selected_token_snapshot']);
        Assert::same($fixture->vault->decrypt(\Tablo\SqlColumn::string($returned, 'selected_token_snapshot')), $original['token']);
        Assert::false($sites->settleWorkerCheck($snapshot, ['checked_at' => 'invalid']));
        self::unchangedSettlement($fixture);
        $fixture->close();
    }

    private static function unchangedSettlement(EngineFixture $fixture): void
    {
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM check_history'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM incidents'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM worker_progress'), 0);
        Assert::same($fixture->scalar('SELECT fairness_turn FROM worker_runtime'), 0);
        Assert::same($fixture->scalar('SELECT committed_history_high_watermark FROM tablo_database_state'), 0);
        Assert::same($fixture->scalar('SELECT COUNT(*) FROM notification_slots'), 0);
    }
}
