<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use JsonException;
use RuntimeException;
use Tablo\Tests\Support\PasswordProcess;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Testo\Assert;
use Testo\Test;

final class ProcessDiagnosticTest
{
    private static function command(string $mode, string $gate = ''): array
    {
        return [PHP_BINARY, dirname(__DIR__) . '/fixtures/process-diagnostic.php', $mode, $gate];
    }

    private static function receipt(string $path): array
    {
        return json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function timeoutRetainsBothStreamsAndClosedReceiptWithoutEchoingInput(): void
    {
        $directory = new TemporaryDirectory('tablo-process-timeout-');
        $child = new Subprocess(self::command('wait', $directory->path . '/never-release'), $directory);
        try {
            $child->awaitOutput('diagnostic stdout marker');
            $child->send("synthetic-input-secret\n");
            try { $child->finish(0.2); Assert::fail('Timeout must remain a failure'); }
            catch (RuntimeException $error) {
                Assert::true(str_contains($error->getMessage(), 'stage=timeout'));
                Assert::true(str_contains($error->getMessage(), 'diagnostic stdout marker'));
                Assert::true(str_contains($error->getMessage(), 'diagnostic stderr marker'));
                Assert::false(str_contains($error->getMessage(), 'synthetic-input-secret'));
            }
            $first = glob($directory->path . '/command-*.json')[0];
            $receipt = self::receipt($first);
            Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
            Assert::same($receipt['ownership'], 'direct-child-only');
            $hashes = array_map(static fn(string $path): string => hash_file('sha256', $path),
                [$first, $receipt['streams']['stdout']['path'], $receipt['streams']['stderr']['path']]);
            $second = Subprocess::run(self::command('nonzero'), $directory, []);
            Assert::same($second['exit_code'], 7);
            Assert::true($first !== $second['files']['receipt']);
            Assert::same(self::receipt($second['files']['receipt'])['exit_code'], 7);
            $directory->close();
            Assert::same(array_map(static fn(string $path): string => hash_file('sha256', $path),
                [$first, $receipt['streams']['stdout']['path'], $receipt['streams']['stderr']['path']]), $hashes);
        } finally { $child->close(); $directory->close(); }
    }

    #[Test]
    public function failedSpawnAndMissingExecutableHaveIndependentSafeReceipts(): void
    {
        $directory = new TemporaryDirectory('tablo-process-spawn-');
        try {
            try { Subprocess::run(["invalid\0synthetic-argv-secret"], $directory, []); Assert::fail('Spawn must fail'); }
            catch (RuntimeException $error) {
                Assert::true(str_contains($error->getMessage(), 'stage=spawn'));
                Assert::false(str_contains($error->getMessage(), 'synthetic-argv-secret'));
            }
            $first = glob($directory->path . '/command-*.json')[0];
            $hash = hash_file('sha256', $first);
            try {
                $missing = Subprocess::run([$directory->path . '/missing-executable'], $directory, []);
                Assert::true($missing['exit_code'] !== 0);
                Assert::true(self::receipt($missing['files']['receipt'])['closed']);
            } catch (RuntimeException $error) { Assert::true(str_contains($error->getMessage(), 'stage=spawn')); }
            Assert::same(count(glob($directory->path . '/command-*.json')), 2);
            Assert::same(hash_file('sha256', $first), $hash);
            $directory->close();
            Assert::true(is_file($first));
        } finally { $directory->close(); }
    }

    #[Test]
    public function malformedOutputCanBeRetainedBeforeFinallyCleanup(): void
    {
        $directory = new TemporaryDirectory('tablo-process-malformed-');
        $result = Subprocess::run(self::command('malformed'), $directory, []);
        $primary = null;
        try { json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { $primary = $error; $directory->retainFailure($error); }
        finally { $directory->close(); }
        Assert::instanceOf($primary, JsonException::class);
        Assert::true(is_file($result['files']['stdout']));
        Assert::same(self::receipt($result['files']['receipt'])['exit_code'], 0);
        Assert::true(str_contains(file_get_contents($result['files']['stdout']), '{malformed'));
    }

    #[Test]
    public function flattenedNestedWorkHasTwoOwnedChildrenBeforeFixtureRelease(): void
    {
        $directory = new TemporaryDirectory('tablo-process-owners-');
        $second = null; $first = null;
        try {
            $first = new Subprocess(self::command('wait', $directory->path . '/release-first'), $directory);
            $second = new Subprocess(self::command('wait', $directory->path . '/release-second'), $directory);
            foreach ([$first, $second] as $child) { $child->awaitOutput('diagnostic stdout marker'); $child->send(''); }
            file_put_contents($directory->path . '/release-first', data: 'release');
            $one = $first->finish();
            Assert::true(self::receipt($one['files']['receipt'])['root_exit_observed']);
            // The second phase is a sibling owned by this parent, never an unowned grandchild.
            file_put_contents($directory->path . '/release-second', data: 'release');
            $two = $second->finish();
            Assert::true(self::receipt($two['files']['receipt'])['closed']);
            Assert::true(self::receipt($one['files']['receipt'])['pid'] !== self::receipt($two['files']['receipt'])['pid']);
        } finally { $first?->close(); $second?->close(); $directory->close(); }
        Assert::false(is_dir($directory->path), 'successful direct children settle before successful cleanup');
    }

    #[Test]
    public function fixtureReleaseRefusesLiveChildAndPreservesPrimaryAndCleanupFailures(): void
    {
        $directory = new TemporaryDirectory('tablo-process-release-');
        $child = new Subprocess(self::command('wait', $directory->path . '/release'), $directory);
        try {
            $child->awaitOutput('diagnostic stdout marker');
            try { $directory->close(); Assert::fail('Live child must prevent release'); }
            catch (RuntimeException $error) { Assert::true(str_contains($error->getMessage(), 'before direct child closure')); }
            $primary = new RuntimeException('synthetic primary secret');
            $directory->retainFailure($primary);
            $directory->cleanup(static function (): void { throw new RuntimeException('synthetic cleanup secret'); });
            $records = array_map(self::receipt(...), glob($directory->path . '/failure-*.json'));
            Assert::true(in_array('primary', array_column($records, 'kind'), strict: true));
            Assert::true(in_array('cleanup', array_column($records, 'kind'), strict: true));
            Assert::false(str_contains(json_encode($records), 'synthetic primary secret'));
            $child->send('');
            file_put_contents($directory->path . '/release', data: 'release');
            Assert::same($child->finish()['exit_code'], 0);
        } finally { $child->close(); $directory->close(); }
        Assert::true(is_dir($directory->path), 'failed synthetic fixture remains retained');
    }

    #[Test]
    public function passwordSelectionFailureRetainsClosedChildAndBothStreams(): void
    {
        $directory = new TemporaryDirectory('tablo-process-selection-');
        try {
            try { new PasswordProcess($directory, 'unused', dirname(__DIR__) . '/fixtures/process-diagnostic.php'); Assert::fail('Missing selection must fail'); }
            catch (RuntimeException $error) {
                Assert::true(str_contains($error->getMessage(), 'stage=selection'));
                Assert::true(str_contains($error->getMessage(), 'diagnostic stdout marker'));
                Assert::true(str_contains($error->getMessage(), 'diagnostic stderr marker'));
            }
            $receipt = self::receipt(glob($directory->path . '/command-*.json')[0]);
            Assert::same($receipt['exit_code'], 0);
            Assert::true($receipt['closed'] && $receipt['root_exit_observed']);
        } finally { $directory->close(); }
    }
}
