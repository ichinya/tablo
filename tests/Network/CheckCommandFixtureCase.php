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
        return $this->sites->save(array_replace(UnitFixtures::site(), ['url' => $this->peer->base,
            'name' => $this->marker, 'repository' => 'fixture/good', 'branch' => 'main', 'health_path' => '/ok',
            'version_path' => '/version'], $changes));
    }

    protected function command(array $arguments, array $options = []): array
    {
        $root = dirname(__DIR__, levels: 2);
        return [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/fixtures/check-transport.php',
            '-d', 'zend.exception_ignore_args=0', ...$options, $root . '/bin/check.php', ...$arguments];
    }

    protected function environment(array $changes = []): array
    {
        return array_replace(['TABLO_DB' => $this->directory->path . '/db.sqlite', 'TABLO_TOKEN_KEY_FILE' => '',
            'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_CHECK_PEER' => $this->peer->base, 'TABLO_CHECK_INJECT' => ''], $changes);
    }

    protected function record(array $result, string $label): void
    {
        $report = getenv('TABLO_CHECK_REPORT');
        if (!$report) { return; }
        $safe = !str_contains($result['stdout'] . $result['stderr'], $this->marker);
        $receipt = json_decode(file_get_contents($result['files']['receipt']), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
        file_put_contents($report, json_encode(['label' => $label, 'utc' => gmdate('c'), 'exit_code' => $result['exit_code'],
            'receipt' => $receipt, 'output_safe' => $safe, 'stdout' => $safe ? $result['stdout'] : null,
            'stderr' => $safe ? $result['stderr'] : null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    protected function run(array $arguments = ['--json'], array $changes = [], array $options = []): array
    {
        $result = Subprocess::run($this->command($arguments, options: $options), $this->directory, $this->environment($changes));
        $this->record($result, implode(' ', $arguments) . ':' . ($changes['TABLO_CHECK_INJECT'] ?? 'normal'));
        Assert::false(str_contains($result['stdout'] . $result['stderr'], $this->marker), 'public streams omit synthetic reflected secrets');
        return $result;
    }

    protected function document(array $result, string $status, int $exit): array
    {
        $document = json_decode($result['stdout'], associative: false, depth: 64, flags: JSON_THROW_ON_ERROR);
        Assert::true(CheckSchema::valid($document), 'actual stdout satisfies every published schema assertion');
        Assert::same($document->run->status, $status); Assert::same($result['exit_code'], $exit);
        Assert::same($document->run->exit_code, $exit);
        foreach ($document->sites as $site) {
            if (!($site->persistence->accepted === true)) { continue; }
 Assert::same($site->persistence->accepted_checked_at, $site->observation->checked_at);
        }
        return json_decode($result['stdout'], associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
    }

    protected function requests(): array
    {
        $path = $this->directory->path . '/requests.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => json_decode($line, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR)['path'], file($path, FILE_IGNORE_NEW_LINES)) : [];
    }


}
