<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use PDO;
use Tablo\DatabaseFailure;
use Tablo\DatabaseTransfer;
use Tablo\Tests\Support\CommandOptions;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TransferPublicationFault;
use Tablo\Tests\Support\TransferSqliteFixture;
use Testo\Assert;
use Testo\Test;

final class DatabaseTransferPublicationTest
{
    #[Test]
    public function publishedImportSurvivesCleanupFailureAndRefusesCompetingTargets(): void
    {
        $modes = ['normal', 'cleanup-false', 'cleanup-throw', 'link-false', 'existing', 'competing'];
        $mode = getenv('TABLO_TEST_TRANSFER_PUBLICATION_CASE');
        if (!is_string($mode) || !in_array($mode, $modes, strict: true)) {
            self::children($modes, __FUNCTION__);
            return;
        }
        require_once dirname(__DIR__) . '/fixtures/transfer-publication-functions.php';
        $fixture = new TransferSqliteFixture();
        try {
            $source = $fixture->source('source.sqlite');
            $archive = $fixture->path('archive'); $scratch = $fixture->path('scratch.sqlite'); $target = $fixture->path('target.sqlite');
            $digest = DatabaseTransfer::export($source, $fixture->vault, $archive, $fixture->path('export.sqlite'), offline: true);
            $source = null;
            if ($mode === 'existing') { file_put_contents($target, TransferPublicationFault::COMPETING_BYTES); }
            TransferPublicationFault::arm($mode, $scratch, $target);
            $caught = null; $result = null;
            try { $result = DatabaseTransfer::importSqlite($fixture->vault, $archive, $scratch, $target, offline: true); }
            catch (\Throwable $error) { $caught = $error; }
            if (!in_array($mode, ['normal', 'cleanup-false', 'cleanup-throw'], strict: true)) {
                self::refused($mode, $caught, $result, $scratch, $target);
                return;
            }
            Assert::same($caught, null); Assert::same($result, $digest);
            $db = new PDO('sqlite:' . $target, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            Assert::same(DatabaseTransfer::verify($db, $fixture->vault, $archive, $fixture->path('verify.sqlite')), $digest);
            Assert::same(file_exists($scratch), $mode !== 'normal');
            if (file_exists($scratch)) {
                Assert::same(hash_file('sha256', $scratch), hash_file('sha256', $target));
                if (PHP_OS_FAMILY !== 'Windows') { Assert::same(fileperms($scratch) & 0o777, 0o600); }
            }
        } catch (\Throwable $error) { $fixture->directory->retainFailure($error); throw $error; }
        finally { $source = null; $db = null; $fixture->directory->close(); }
    }

    /** @param list<string> $modes */
    private static function children(array $modes, string $function): void
    {
        $directory = new TemporaryDirectory('tablo-transfer-publication-child-');
        try {
            foreach ($modes as $case) {
                $result = Subprocess::run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/vendor/bin/testo', 'run',
                    '--path', __FILE__, '--filter', $function, '--json'], $directory,
                    ['TABLO_TEST_TRANSFER_PUBLICATION_CASE' => $case, 'TABLO_TOKEN_KEY_FILE' => ''], new CommandOptions(timeout: 20));
                Assert::same($result['exit_code'], 0, $case . ': ' . $result['stdout'] . $result['stderr']);
                Assert::false(str_contains($result['stdout'] . $result['stderr'], 'synthetic-private-cleanup-'));
                $report = \Tablo\JsonRecord::decode($result['stdout']);
                $totals = \Tablo\JsonRecord::checked($report['totals'] ?? null);
                Assert::same(\Tablo\SqlColumn::integer($totals, 'total'), 1);
                Assert::same(\Tablo\SqlColumn::integer($totals, 'passed'), 1);
                Assert::true(\Tablo\SqlColumn::integer($totals, 'assertions') >= 4);
            }
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $directory->close(); }
    }

    private static function refused(string $mode, ?\Throwable $caught, ?string $result, string $scratch, string $target): void
    {
        Assert::true($caught instanceof DatabaseFailure);
        if (!$caught instanceof DatabaseFailure) { throw new \RuntimeException('Expected typed publication refusal.'); }
        Assert::same($caught->category, $mode === 'existing' ? 'configuration' : 'unavailable');
        Assert::same($caught->getPrevious(), null); Assert::same($result, null);
        if ($mode === 'link-false') { Assert::false(file_exists($target)); }
        if ($mode !== 'link-false') { Assert::same(file_get_contents($target), TransferPublicationFault::COMPETING_BYTES); }
        Assert::same(file_exists($scratch), $mode !== 'existing');
    }
}
