<?php
declare(strict_types=1);

namespace Tablo\Tests\Unit;

use Tablo\CheckCommand;
use Tablo\CheckFormat;
use Tablo\CheckOutput;
use Tablo\DatabaseFailure;
use Tablo\GitHubConnection;
use Tablo\HttpClient;
use Tablo\SchemaReference;
use Tablo\SiteChecker;
use Tablo\SiteRepository;
use Tablo\Tests\Support\CheckSchema;
use Tablo\Tests\Support\FakeHttp;
use Tablo\Tests\Support\FakeProvider;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;
use Testo\Test;

final class DatabaseFailureOutputTest
{
    #[Test]
    public function nativeHttpAndEndpointChecksDoNotReclassifyTypedStorageFailures(): void
    {
        $failure = new DatabaseFailure('unavailable');
        $http = new class($failure) extends HttpClient {
            public function __construct(private readonly DatabaseFailure $failure) { parent::__construct(); }
            protected function resolve(#[\SensitiveParameter] string $host): array { throw $this->failure; }
        };
        self::sameFailure(static fn () => $http->get('https://synthetic.invalid/'), $failure);
        self::sameFailure(static fn () => $http->getEndpoint('https://synthetic.invalid/up'), $failure);
        foreach ([[new FakeHttp([$failure]), ''], [new FakeHttp([UnitFixtures::response(), $failure]), '/version']] as [$fake, $version]) {
            self::sameFailure(static fn () => (new SiteChecker($fake, new FakeProvider()))->check([...UnitFixtures::site(), 'version_path' => $version]), $failure);
        }
        $ordinary = (new SiteChecker(new FakeHttp([new \RuntimeException('private')]), new FakeProvider()))->check([...UnitFixtures::site(), 'version_path' => '']);
        Assert::same($ordinary['health_error_code'], 'check-error');
        Assert::false(str_contains($ordinary['last_error'] ?? '', 'private'));
    }

    #[Test]
    public function jsonCommandKeepsEarlierSuccessAndAbortsRemainingSitesAsStorageFailure(): void
    {
        $directory = new TemporaryDirectory('tablo-json-database-failure-');
        try {
            $db = new FailureBoundaryPdo($directory->path . '/database.sqlite');
            SchemaReference::reference(10, ['endpoint' => true], $db); $db->exec('PRAGMA main.user_version = 10');
            $vault = new \Tablo\TokenVault($directory->path . '/key');
            $sites = new SiteRepository($db, $vault);
            for ($index = 0; $index < 3; ++$index) { $sites->save([...UnitFixtures::site(), 'name' => 'Synthetic ' . $index, 'version_path' => '']); }
            $failure = new DatabaseFailure('unavailable');
            $http = new FakeHttp([UnitFixtures::response(), UnitFixtures::response(200, '{"tag_name":"v1"}'),
                UnitFixtures::response(200, '{"name":"main","commit":{"sha":"' . str_repeat('a', times: 40) . '"}}'),
                UnitFixtures::response(200, '{"total_count":0,"incomplete_results":false}'),
                UnitFixtures::response(200, '{"total_count":0,"incomplete_results":false}'), $failure]);
            $command = new CheckCommand($sites, new GitHubConnection($sites, $http, propagateSharedFailures: true), $http, $vault);
            ob_start();
            try { $exit = $command->run(CheckFormat::Json); $bytes = \Tablo\RequiredFile::bytes(ob_get_contents()); }
            finally { ob_end_clean(); }
            Assert::same($exit, 1); Assert::same(CheckOutput::fatalReason($failure), 'storage-failed');
            /** @var mixed $document The schema validator checks the native decoded JSON value. */
            $document = json_decode($bytes, associative: false, flags: JSON_THROW_ON_ERROR);
            Assert::true(CheckSchema::valid($document));
            $record = \Tablo\JsonRecord::decode($bytes); $run = \Tablo\JsonRecord::checked($record['run'] ?? null);
            $rows = \Tablo\SqlRows::all(\Tablo\JsonRecord::checked($record['sites'] ?? null), \Tablo\JsonRecord::checked(...));
            Assert::same([$run['status'] ?? null, $run['reason'] ?? null], ['fatal', 'storage-failed']);
            Assert::same(array_column($rows, 'status'), ['checked', 'failed', 'skipped']);
            Assert::same(array_column($rows, 'reason'), ['ok', 'storage-failed', 'run-aborted']);
            Assert::same(count($http->requests), 6);
            Assert::same(\Tablo\SqlStatement::checked($db->query('SELECT COUNT(*) FROM sites WHERE checked_at IS NOT NULL'))->fetchColumn(), 1);
        } catch (\Throwable $error) { $directory->retainFailure($error); throw $error; }
        finally { $command = null; $sites = null; $vault = null; $db = null; $directory->close(); }
    }

    /** @param \Closure(): mixed $operation */
    private static function sameFailure(\Closure $operation, DatabaseFailure $failure): void
    {
        $caught = null;
        try { $operation(); } catch (\Throwable $error) { $caught = $error; }
        Assert::same($caught, $failure);
    }
}
