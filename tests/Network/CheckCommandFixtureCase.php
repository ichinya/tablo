<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Tablo\Database;
use Tablo\SiteRepository;
use Tablo\TokenVault;
use Tablo\Tests\Support\CheckSchema;
use Tablo\Tests\Support\Subprocess;
use Tablo\Tests\Support\TemporaryDirectory;
use Tablo\Tests\Support\TestServer;
use Tablo\Tests\Support\UnitFixtures;
use Testo\Assert;

/**
 * @phpstan-import-type EnvelopeArray from CheckSchema
 * @phpstan-import-type Result from Subprocess
 */
abstract class CheckCommandFixtureCase
{
    protected ?TemporaryDirectory $directory = null;
    protected ?TestServer $peer = null;
    protected ?\PDO $db = null;
    protected ?SiteRepository $sites = null;
    protected string $marker = '';

    protected function start(): void
    {
        $this->directory = new TemporaryDirectory('tablo-check-json-');
        $this->marker = 'synthetic-' . bin2hex(random_bytes(16));
        $this->db = Database::connect($this->directory->path . '/db.sqlite');
        $this->sites = new SiteRepository($this->db, TokenVault::forDatabase($this->db));
        $this->peer = new TestServer($this->directory, dirname(__DIR__) . '/fixtures/check-router.php', environment: [
            'TABLO_CHECK_DIRECTORY' => $this->directory->path, 'TABLO_CHECK_MARKER' => $this->marker]);
    }

    protected function close(): void
    {
        $this->db = null; $this->sites = null;
        gc_collect_cycles();
        $this->peer?->close();
        $this->peer = null;
        $this->directory?->close();
        $this->directory = null;
    }

    protected function row(array $changes = []): int
    {
        return \Tablo\Tests\Support\FixtureValue::object($this->sites)->save(array_replace(UnitFixtures::site(), ['url' => \Tablo\Tests\Support\FixtureValue::object($this->peer)->base,
            'name' => $this->marker, 'repository' => 'fixture/good', 'branch' => 'main', 'health_path' => '/ok',
            'version_path' => '/version'], $changes));
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $options
     * @return list<string>
     */
    protected function command(array $arguments, array $options = []): array
    {
        $root = dirname(__DIR__, levels: 2);
        return [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/fixtures/check-transport.php',
            '-d', 'zend.exception_ignore_args=0', ...$options, $root . '/bin/check.php', ...$arguments];
    }

    /**
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    protected function environment(array $changes = []): array
    {
        return array_replace(['TABLO_DB' => \Tablo\Tests\Support\FixtureValue::object($this->directory)->path . '/db.sqlite', 'TABLO_TOKEN_KEY_FILE' => '',
            'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_CHECK_PEER' => \Tablo\Tests\Support\FixtureValue::object($this->peer)->base, 'TABLO_CHECK_INJECT' => ''], $changes);
    }

    /** @param Result $result */
    protected function record(array $result, string $label): void
    {
        $report = getenv('TABLO_CHECK_REPORT');
        if (!$report) { return; }
        $safe = !str_contains($result['stdout'] . $result['stderr'], $this->marker);
        $receipt = \Tablo\Tests\Support\SubprocessReceipt::read($result['files']['receipt']);
        file_put_contents($report, json_encode(['label' => $label, 'utc' => gmdate('c'), 'exit_code' => $result['exit_code'],
            'receipt' => $receipt, 'output_safe' => $safe, 'stdout' => $safe ? $result['stdout'] : null,
            'stderr' => $safe ? $result['stderr'] : null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $changes
     * @param list<string> $options
     * @return Result
     */
    protected function run(array $arguments = ['--json'], array $changes = [], array $options = []): array
    {
        $result = Subprocess::run($this->command($arguments, options: $options), \Tablo\Tests\Support\FixtureValue::object($this->directory), $this->environment($changes));
        $this->record($result, implode(' ', $arguments) . ':' . ($changes['TABLO_CHECK_INJECT'] ?? 'normal'));
        Assert::false(str_contains($result['stdout'] . $result['stderr'], $this->marker), 'public streams omit synthetic reflected secrets');
        return $result;
    }

    /**
     * @param Result $result
     * @return EnvelopeArray
     */
    protected function document(array $result, string $status, int $exit): array
    {
        $document = CheckSchema::document($result['stdout'], 'actual stdout satisfies every published schema assertion');
        // The same immutable JSON bytes just satisfied the published schema before associative decoding.
        /** @var EnvelopeArray $decoded */
        $decoded = json_decode($result['stdout'], associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        Assert::same($document->run->status, $status); Assert::same($result['exit_code'], $exit);
        Assert::same($document->run->exit_code, $exit);
        foreach ($document->sites as $site) {
            if (!($site->persistence->accepted === true)) { continue; }
 Assert::same($site->persistence->accepted_checked_at, \Tablo\Tests\Support\FixtureValue::object($site->observation)->checked_at);
        }
        return $decoded;
    }

    /** @return list<string> */
    protected function requests(): array
    {
        $path = \Tablo\Tests\Support\FixtureValue::object($this->directory)->path . '/requests.jsonl';
        if (!is_file($path)) { return []; }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) { throw new \RuntimeException('Cannot read fixture request records'); }
        return array_map(static fn (string $line): string => \Tablo\SqlColumn::string(\Tablo\JsonRecord::decode($line), 'path'), array_values($lines));
    }


}
