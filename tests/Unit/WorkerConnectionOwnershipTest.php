<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class WorkerConnectionOwnershipTest
{
    #[Test]
    public function differentConnectionRefusesBeforeKeyEffectsAndPreservesBothCallerTransactions(): void
    {
        $directory = new TemporaryDirectory('tablo-settlement-connection-');
        $environment = getenv('TABLO_TOKEN_KEY_FILE');
        $vault = null; $state = null; $sitesB = null; $sitesA = null; $b = null; $a = null;
        try {
            putenv('TABLO_TOKEN_KEY_FILE');
            [$key, $original, $vault, $a, $b, $sitesA, $sitesB, $idA, $idB, $snapshot, $result] = ConnectionOwnershipFixture::create($directory);
            $snapshot = \Tablo\Tests\Support\FixtureValue::row($snapshot);
            foreach ([false, true] as $callerTransaction) {
                if ($callerTransaction) {
                    CallerTransactionFixture::open($a, $b, $key);
                }
                $caught = null;
                try {
                    $state = new WorkerStateRepository($a, $sitesB);
                    $state->settle(\Tablo\Tests\Support\FixtureValue::row($snapshot), $result);
                } catch (\LogicException $error) { $caught = $error->getMessage(); unset($error); }
                Assert::same($caught, 'Settlement repository connection mismatch.');
                Assert::same($a->inTransaction(), $callerTransaction);
                Assert::same($b->inTransaction(), $callerTransaction);
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($sitesA->find($idA))['checked_at'], null);
                Assert::same(\Tablo\Tests\Support\FixtureValue::row($sitesB->find($idB))['checked_at'], null);
                (new ConnectionOwnershipAssertions($a, $b))->noCredit();
                if ($callerTransaction) {
                    (new ConnectionOwnershipAssertions($a, $b))->caller($key);
                }
            }
            (new ConnectionCreditAssertions($a, $sitesA, $sitesB))->refused([$key, $original, $idA, $idB, $result]);
        } finally {
            $vault = null; $b = null; $a = null; $sitesB = null; $sitesA = null; $state = null;
            putenv($environment === false ? 'TABLO_TOKEN_KEY_FILE' : 'TABLO_TOKEN_KEY_FILE=' . $environment);
            $directory->close();
        }
    }
}
