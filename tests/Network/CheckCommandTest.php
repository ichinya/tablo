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
use Testo\Test;

final class CheckCommandTest
{
    private ?TemporaryDirectory $directory = null;
    private ?TestServer $peer = null;
    private ?\PDO $db = null;
    private ?SiteRepository $sites = null;
    private string $marker = '';

    private function start(): void
    {
        $this->directory = new TemporaryDirectory('tablo-check-json-');
        $this->marker = 'synthetic-' . bin2hex(random_bytes(16));
        $this->db = Database::connect($this->directory->path . '/db.sqlite');
        $this->sites = new SiteRepository($this->db, TokenVault::forDatabase($this->db));
        $this->peer = new TestServer($this->directory, dirname(__DIR__) . '/fixtures/check-router.php', environment: [
            'TABLO_CHECK_DIRECTORY' => $this->directory->path, 'TABLO_CHECK_MARKER' => $this->marker]);
    }

    private function close(): void
    {
        $this->sites = $this->db = null;
        gc_collect_cycles();
        $this->peer?->close();
        $this->peer = null;
        $this->directory?->close();
        $this->directory = null;
    }

    private function row(array $changes = []): int
    {
        return $this->sites->save(array_replace(UnitFixtures::site(), ['url' => $this->peer->base,
            'name' => $this->marker, 'repository' => 'fixture/good', 'branch' => 'main', 'health_path' => '/ok',
            'version_path' => '/version'], $changes));
    }

    private function command(array $arguments, array $options = []): array
    {
        $root = dirname(__DIR__, 2);
        return [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/fixtures/check-transport.php',
            '-d', 'zend.exception_ignore_args=0', ...$options, $root . '/bin/check.php', ...$arguments];
    }

    private function environment(array $changes = []): array
    {
        return array_replace(['TABLO_DB' => $this->directory->path . '/db.sqlite', 'TABLO_TOKEN_KEY_FILE' => '',
            'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_CHECK_PEER' => $this->peer->base, 'TABLO_CHECK_INJECT' => ''], $changes);
    }

    private function record(array $result, string $label): void
    {
        $report = getenv('TABLO_CHECK_REPORT');
        if (!$report) { return; }
        $safe = !str_contains($result['stdout'] . $result['stderr'], $this->marker);
        $receipt = json_decode(file_get_contents($result['files']['receipt']), true, 32, JSON_THROW_ON_ERROR);
        file_put_contents($report, json_encode(['label' => $label, 'utc' => gmdate('c'), 'exit_code' => $result['exit_code'],
            'receipt' => $receipt, 'output_safe' => $safe, 'stdout' => $safe ? $result['stdout'] : null,
            'stderr' => $safe ? $result['stderr'] : null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function run(array $arguments = ['--json'], array $changes = [], array $options = []): array
    {
        $result = Subprocess::run($this->command($arguments, options: $options), $this->directory, $this->environment($changes));
        $this->record($result, implode(' ', $arguments) . ':' . ($changes['TABLO_CHECK_INJECT'] ?? 'normal'));
        Assert::false(str_contains($result['stdout'] . $result['stderr'], $this->marker), 'public streams omit synthetic reflected secrets');
        return $result;
    }

    private function document(array $result, string $status, int $exit): array
    {
        $document = json_decode($result['stdout'], false, 64, JSON_THROW_ON_ERROR);
        Assert::true(CheckSchema::valid($document), 'actual stdout satisfies every published schema assertion');
        Assert::same($document->run->status, $status); Assert::same($result['exit_code'], $exit);
        Assert::same($document->run->exit_code, $exit);
        foreach ($document->sites as $site) {
            if ($site->persistence->accepted === true) { Assert::same($site->persistence->accepted_checked_at, $site->observation->checked_at); }
        }
        return json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
    }

    private function requests(): array
    {
        $path = $this->directory->path . '/requests.jsonl';
        return is_file($path) ? array_map(static fn (string $line): string => json_decode($line, true, 8, JSON_THROW_ON_ERROR)['path'], file($path, FILE_IGNORE_NEW_LINES)) : [];
    }

    #[Test]
    public function emptyDisabledMixedAndStorageFailureHaveDifferentActualResults(): void
    {
        try {
            $this->start();
            $empty = $this->document($this->run(), 'success', 0); Assert::same($empty['run']['reason'], 'empty');
            $disabled = $this->row(['enabled' => '0', 'health_path' => '/forbidden']);
            $paused = $this->document($this->run(), 'success', 0);
            Assert::same($paused['sites'][0]['status'], 'paused'); Assert::same($this->requests(), []);
            Assert::same($this->sites->find($disabled)['checked_at'], null);
            $enabled = $this->row();
            $mixed = $this->document($this->run(), 'success', 0); Assert::same(count($mixed['sites']), 2);
            Assert::same($mixed['sites'][1]['id'], $enabled); Assert::same(count($this->requests()), 6);
            Assert::false(in_array('/forbidden', $this->requests(), true));
            $failed = $this->document($this->run(changes: ['TABLO_DB' => $this->directory->path]), 'fatal', 1);
            Assert::same($failed['sites'], []); Assert::false($failed['run']['reason'] === 'empty');
            Assert::same($this->db->query('SELECT run_id FROM worker_runtime')->fetchColumn(), null);
            Assert::same((int) $this->db->query('SELECT COUNT(*) FROM worker_progress')->fetchColumn(), 0);
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }

    #[Test]
    public function independentFailuresConfirmedNullZeroAndCooldownSurviveProjection(): void
    {
        try {
            $this->start();
            $id = $this->row(['repository' => 'fixture/no-release', 'version_path' => '']);
            $document = $this->document($this->run(), 'success', 0); $observation = $document['sites'][0]['observation'];
            Assert::same($observation['git']['fields']['latest_release']['status'], 'absent');
            Assert::same($observation['git']['fields']['open_issues']['value'], 0);
            Assert::same($observation['version']['status'], 'skipped');
            $this->row(['repository' => 'fixture/commit-fails', 'version_path' => '/bad-version']);
            $document = $this->document($this->run(), 'partial', 1); $observation = $document['sites'][1]['observation'];
            Assert::same($observation['health']['status'], 'online'); Assert::same($observation['version']['status'], 'failed');
            Assert::same($observation['git']['fields']['latest_release']['status'], 'ok');
            Assert::same($observation['git']['fields']['latest_commit']['value'], null);
            Assert::same($observation['git']['fields']['open_prs']['value'], 0);
            $this->sites->delete($id); $this->sites->delete(2);
            $this->row(['repository' => 'fixture/core-limited', 'health_path' => '/offline']);
            $document = $this->document($this->run(), 'partial', 1); $observation = $document['sites'][0]['observation'];
            Assert::same($observation['health']['status'], 'offline'); Assert::same($observation['health']['http_status'], 503);
            Assert::same($observation['version']['status'], 'ok'); Assert::same($observation['git']['status'], 'partial');
            Assert::same($observation['git']['fields']['latest_release']['reason'], 'rate-limit');
            Assert::true(is_string($observation['git']['fields']['latest_release']['eligible_at']));
            $before = count(array_filter($this->requests(), static fn (string $path): bool => str_starts_with($path, '/repos/')));
            $document = $this->document($this->run(), 'partial', 1);
            Assert::same(count(array_filter($this->requests(), static fn (string $path): bool => str_starts_with($path, '/repos/'))), $before,
                'rendering and a fresh cooldown process add no provider admission');
            Assert::same($document['sites'][0]['observation']['git']['fields']['open_issues']['value'], 0);
            $document = $this->document($this->run(changes: ['TABLO_ALLOW_PRIVATE_NETWORK' => '0']), 'partial', 1);
            Assert::same($document['sites'][0]['observation']['health']['status'], 'unknown');
            Assert::same($document['sites'][0]['observation']['health']['reason'], 'ssrf');
            Assert::same($document['sites'][0]['observation']['health']['http_status'], null);
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }

    #[Test]
    public function configTokenAndDeleteRacesRejectActualWritesWithoutGuessingCause(): void
    {
        foreach (['config', 'token', 'delete'] as $race) {
            $child = null;
            try {
                $this->start(); $id = $this->row(['health_path' => '/barrier', 'github_token' => $this->marker]);
                $previous = '2000-01-01T00:00:00Z';
                $this->db->exec("UPDATE sites SET checked_at = '2000-01-01T00:00:00Z' WHERE id = " . $id);
                $child = new Subprocess($this->command(['--json']), $this->directory, $this->environment()); $child->send('');
                $deadline = microtime(true) + 5;
                while (!is_file($this->directory->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
                Assert::true(is_file($this->directory->path . '/entered'), 'actual endpoint race barrier entered');
                if ($race === 'delete') { $this->sites->delete($id); }
                else { $this->sites->save(array_replace(UnitFixtures::site(), ['name' => $this->marker, 'url' => $this->peer->base,
                    'repository' => 'fixture/good', 'branch' => 'main', 'health_path' => '/barrier', 'version_path' => '/version',
                    'github_token' => $race === 'token' ? 'synthetic-rotated-token' : '', 'sort_order' => $race === 'config' ? '7' : '0']), $id); }
                file_put_contents($this->directory->path . '/release', 'go');
                $result = $child->finish(); $this->record($result, 'actual-race:' . $race);
                $document = $this->document($result, 'partial', 1); $site = $document['sites'][0];
                Assert::same($site['reason'], 'stale-result'); Assert::same($site['persistence']['accepted'], false);
                Assert::same($site['persistence']['previous_checked_at'], $previous); Assert::same($site['persistence']['previous_freshness'], 'stale');
                Assert::same($site['persistence']['accepted_checked_at'], null); Assert::same($site['observation']['health']['status'], 'online');
                Assert::false($site['observation']['checked_at'] === $previous);
                Assert::same($this->db->query('SELECT run_id FROM worker_runtime')->fetchColumn(), null);
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $child?->close(); $child = null; $this->close(); }
        }
    }

    #[Test]
    public function lateSharedAndEncodingFailuresRetainPriorCompleteFragments(): void
    {
        foreach (['drop', 'key-loss', 'encode', 'encode-after-store', 'timestamp', 'scalar'] as $failure) {
            try {
                $this->start(); $this->row(['github_token' => $this->marker]);
                $this->row(['health_path' => $failure === 'scalar' ? '' : (in_array($failure, ['drop', 'key-loss'], true) ? '/' . $failure : '/ok')]);
                $this->row();
                $result = $this->run(changes: ['TABLO_CHECK_INJECT' => in_array($failure, ['drop', 'key-loss'], true) ? '' : $failure]);
                $document = $this->document($result, 'fatal', 1); Assert::same(count($document['sites']), 3);
                Assert::same($document['sites'][0]['persistence']['accepted'], true); Assert::same($document['sites'][0]['reason'], 'ok');
                Assert::same($document['sites'][1]['status'], 'failed'); Assert::same($document['sites'][2]['status'], 'skipped');
                Assert::same($document['run']['reason'], match ($failure) { 'drop' => 'storage-failed', 'key-loss' => 'key-unavailable', default => 'serialization-failed' });
                if ($failure === 'encode') { Assert::same($document['sites'][1]['persistence']['status'], 'not-attempted'); }
                if ($failure === 'encode-after-store') { Assert::same($document['sites'][1]['persistence']['accepted'], true); }
                if ($failure === 'key-loss') { Assert::false(is_file($this->directory->path . '/github-token.key')); }
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $this->close(); }
        }
    }

    #[Test]
    public function keyRefusalsAndOneDamagedCredentialKeepTheirExistingBoundaries(): void
    {
        foreach (['missing', 'corrupt', 'unverified', 'individual'] as $failure) {
            try {
                $this->start(); $id = $this->row(['github_token' => $this->marker]);
                if (in_array($failure, ['unverified', 'individual'], true)) {
                    $this->db->exec("UPDATE sites SET github_token = 'damaged-ciphertext' WHERE id = " . $id);
                }
                if ($failure === 'individual') { $this->row(['github_token' => $this->marker]); }
                $changes = [];
                if ($failure === 'missing') { unlink($this->directory->path . '/github-token.key'); }
                if ($failure === 'corrupt') { file_put_contents($this->directory->path . '/github-token.key', 'short'); }
                if ($failure === 'unverified') { rename($this->directory->path . '/github-token.key', $this->directory->path . '/external'); $changes['TABLO_TOKEN_KEY_FILE'] = $this->directory->path . '/external'; }
                $document = $this->document($this->run(changes: $changes), $failure === 'individual' ? 'partial' : 'fatal', 1);
                if ($failure === 'individual') { Assert::same($document['sites'][0]['reason'], 'site-validation-failed'); Assert::same($document['sites'][1]['reason'], 'ok'); }
                else { Assert::same($this->requests(), []); Assert::same($document['sites'], []); }
                if ($failure === 'missing') { Assert::false(is_file($this->directory->path . '/github-token.key')); }
                if ($failure === 'corrupt') { Assert::same(file_get_contents($this->directory->path . '/github-token.key'), 'short'); }
                if ($failure === 'unverified') { Assert::false(is_file($this->directory->path . '/github-token.key')); }
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $this->close(); }
        }
    }

    #[Test]
    public function explicitArgumentsBootstrapUtf8ReflectionAndLegacyTextAreSafe(): void
    {
        try {
            $this->start();
            foreach ([['--json', '--json'], ['--json', '--site=1'], ['--json', '--unknown'], ['--json', '--help', '--help']] as $args) {
                $document = $this->document($this->run($args, ['TABLO_DB' => $this->directory->path . '/absent/no.sqlite']), 'fatal', 1);
                Assert::same($document['run']['reason'], 'invalid-arguments'); Assert::false(is_dir($this->directory->path . '/absent'));
            }
            $document = $this->document($this->run(['--help', '--json'], ['TABLO_DB' => $this->directory->path . '/absent/no.sqlite']), 'success', 0);
            Assert::same($document['run']['reason'], 'help'); Assert::false(is_dir($this->directory->path . '/absent'));
            $document = $this->document($this->run(options: ['-d', 'opcache.enable_cli=0', '-d', 'disable_functions=curl_version']), 'fatal', 1);
            Assert::same($document['run']['reason'], 'installation-failed');
            $id = $this->row(['endpoint_token' => $this->marker, 'version_path' => '/reflect']);
            $document = $this->document($this->run(), 'partial', 1);
            Assert::same($document['sites'][0]['observation']['version']['reason'], 'invalid-response');
            $text = $this->run(['--help', '--site=999', '--unknown']); Assert::same($text['stdout'], $id . ': attention' . PHP_EOL); Assert::same($text['exit_code'], 1);
            $this->sites->delete($id); $id = $this->row(['version_path' => '/utf8']);
            $document = $this->document($this->run(), 'partial', 1); Assert::same($document['sites'][0]['observation']['version']['status'], 'failed');
            $this->sites->delete($id); $id = $this->row(['version_path' => '']);
            $text = $this->run(); $this->document($text, 'success', 0);
            $legacy = $this->run([]); Assert::same($legacy['stdout'], $id . ': ok' . PHP_EOL); Assert::same($legacy['stderr'], ''); Assert::same($legacy['exit_code'], 0);
            Assert::true(str_contains('positive leak control: ' . $this->marker, $this->marker), 'scanner positive control must detect the same marker');
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }
}
