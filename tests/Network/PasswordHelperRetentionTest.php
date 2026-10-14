<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use JsonException;
use RuntimeException;
use PDO;
use ReflectionMethod;
use Throwable;
use Tablo\Tests\Support\PasswordHelperCase;
use Tablo\Tests\Support\PasswordHelperEvidence;
use Tablo\Tests\Support\FixtureValue;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\JsonRecord;
use Testo\Assert;
use Testo\Test;

/** @phpstan-import-type Receipt from \Tablo\Tests\Support\SubprocessReceiptData */
final class PasswordHelperRetentionTest
{
    /** @return array */
    private static function helperRecord(string $path): array
    { return JsonRecord::checked(json_decode(\Tablo\RequiredFile::bytes(file_get_contents($path)), associative: true, flags: JSON_THROW_ON_ERROR)); }

    /** @return Receipt */
    private static function receipt(string $path): array
    { return \Tablo\Tests\Support\SubprocessReceipt::read($path); }

    #[Test]
    public function helperInnerExitMarkerAndSilentChildRemainGenuineTimeoutsWithFullEvidence(): void
    {
        foreach (['metadata-live', 'silent'] as $mode) {
            $directory = new TemporaryDirectory('tablo-helper-timeout-control-');
            $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::diagnostic($mode));
            $child = new Subprocess($evidence->case->argv, $directory);
            try {
                if ($mode === 'metadata-live') { $child->awaitOutput('HELPER_METADATA='); }
                $child->send('');
                try { $child->finish(0.2); Assert::fail('A live child must time out despite inner exit0 metadata'); }
                catch (RuntimeException $error) {
                    Assert::true(str_contains($error->getMessage(), 'stage=timeout'));
                    $evidence->failure($error);
                }
                $receipt = self::receipt($evidence->path . '/final.receipt.json');
                Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
                Assert::true($receipt['pid'] !== null);
                Assert::true($receipt['exit_code'] !== null && $receipt['exit_code'] !== 0);
                Assert::true(in_array('termination-request-returned', array_column($receipt['events'], 'stage'), strict: true));
                Assert::true(in_array('after-termination-wait', array_column($receipt['events'], 'stage'), strict: true));
                $stdout = \Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stdout'));
                $stderr = \Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stderr'));
                Assert::same($stderr, '');
                Assert::same($stdout, $mode === 'silent' ? '' : "HELPER_METADATA={\"helperExit\":0}\n");
                $final = self::helperRecord($evidence->path . '/final.json');
                Assert::same(FixtureValue::at($final, 'ownership'), 'direct-child-only');
                Assert::true(str_starts_with(FixtureValue::string(FixtureValue::at($final, 'nested_closure')), 'UNKNOWN'));
                $snapshots = \Tablo\Tests\Support\FixtureFiles::matches($evidence->path . '/snapshot-*.json');
                Assert::true(count($snapshots) >= 3);
                Assert::true(str_starts_with(FixtureValue::string(FixtureValue::at(self::helperRecord(FixtureValue::at($snapshots, 0)), 'classification')), 'SNAPSHOT'));
                $evidence->assertCollected();
            } finally { $child->close(); $directory->close(); }
            Assert::true(is_dir($directory->path), 'failed timeout fixture remains');
        }
    }

    #[Test]
    public function actualHelperMetadataFailuresAfterClosedSuccessRetainOriginalStreamsReceiptAndDatabase(): void
    {
        foreach (['malformed', 'ok'] as $mode) {
            $directory = new TemporaryDirectory('tablo-helper-assertion-control-');
            $evidence = new PasswordHelperEvidence($directory, PasswordHelperCase::diagnostic($mode));
            $dbPath = $directory->path . '/synthetic.sqlite';
            $db = new PDO('sqlite:' . $dbPath);
            $db->exec("CREATE TABLE synthetic_marker (value TEXT); INSERT INTO synthetic_marker VALUES ('retention-control')");
            $db = null;
            $result = Subprocess::run($evidence->case->argv, $directory, []);
            Assert::same($result['exit_code'], 0);
            $receipt = self::receipt($result['files']['receipt']);
            Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
            $paths = [$dbPath, ...array_values($result['files'])];
            $hashes = array_map(\Tablo\RequiredFile::digest(...), $paths);
            $failure = new \Tablo\Tests\Support\PasswordHelperFailureState();
            try {
                try {
                    // Invoke the unchanged real helper assertions, never a replacement assertion.
                    (new ReflectionMethod(PasswordHelperTest::class, 'assertMetadata'))->invoke(null, $result, $mode === 'malformed' ? 0 : 7);
                } catch (Throwable $error) {
                    $failure->primary = $error;
                    $directory->retainFailure($error);
                    $evidence->failure($error);
                    throw $error;
                } finally { $directory->cleanup($directory->close(...)); }
            } catch (Throwable $returned) { $failure->returned = $returned; }
            Assert::same($failure->returned, $failure->primary);
            Assert::instanceOf($failure->primary, Throwable::class);
            Assert::same($failure->primary instanceof JsonException, $mode === 'malformed');
            Assert::same(array_map(\Tablo\RequiredFile::digest(...), $paths), $hashes);
            Assert::same(\Tablo\RequiredFile::bytes(file_get_contents($evidence->path . '/final.stdout')), $result['stdout']);
            Assert::same(\Tablo\RequiredFile::digest($evidence->path . '/final.receipt.json'), \Tablo\RequiredFile::digest($result['files']['receipt']));
            $db = new PDO('sqlite:' . $dbPath);
            $db->exec('BEGIN EXCLUSIVE');
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT value FROM synthetic_marker'))->fetchColumn(), 'retention-control');
            $db->exec('COMMIT');
            $db = null;
            $directory->close();
            Assert::same(\Tablo\RequiredFile::digest($dbPath), FixtureValue::at($hashes, 0));
        }
    }

}
