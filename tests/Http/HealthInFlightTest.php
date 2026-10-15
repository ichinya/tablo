<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;
use Testo\Test;
use Tablo\SiteRepository;
use Tablo\WorkerStateRepository;
use Tablo\Tests\Support\WorkerProcess;
use Tablo\Tests\Support\HealthCheckDiagnostics;
use Tablo\Tests\Support\HealthCheckFixture;

final class HealthInFlightTest
{
    use HealthCheckFixture;

    #[Test]
    public function realInFlightHomepageResultsRefuseRestoredConfigurationInEveryRunner(): void
    {
        $csrf = $this->web->authenticate();
        $sites = new SiteRepository($this->web->database());
        $state = new WorkerStateRepository($this->web->database());
        $child = null;
        try {
            foreach (['manual', 'cli', 'worker'] as $mode) {
                $input = array_replace(SiteRepository::defaults(), ['name' => 'In flight homepage',
                    'url' => $this->server->base . '/barrier/', 'health_path' => '', 'version_path' => '', 'repository' => 'fixture/public']);
                $id = $sites->save($input);
                foreach (['entered', 'release'] as $file) { if (is_file($this->endpoints->path . '/' . $file)) { unlink($this->endpoints->path . '/' . $file); } }
                $root = dirname(__DIR__, 2);
                $command = $mode === 'manual' ? [PHP_BINARY, '-r',
                    '$c=curl_init($argv[1]);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(["_csrf"=>$argv[3]]),CURLOPT_COOKIEFILE=>$argv[2],CURLOPT_PROXY=>"",CURLOPT_TIMEOUT=>10]);curl_exec($c);exit(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===303?0:1);',
                    $this->web->server->base . '/sites/' . $id . '/check', $this->web->directory->path . '/cookies', $csrf]
                    : ($mode === 'cli' ? [PHP_BINARY, '-d', 'auto_prepend_file=' . $root . '/tests/cli-github-fixture.php', $root . '/bin/check.php']
                    : [PHP_BINARY, $root . '/tests/fixtures/worker-process.php']);
                $child = new WorkerProcess($this->endpoints, 'inflight-' . $mode, $command, [
                    'TABLO_DB' => $this->web->directory->path . '/test.sqlite', 'TABLO_ALLOW_PRIVATE_NETWORK' => '1', 'TABLO_WORKER_BASE' => $this->server->base]);
                $deadline = microtime(true) + 5;
                while (!is_file($this->endpoints->path . '/entered') && microtime(true) < $deadline) { clearstatcache(); usleep(20000); }
                Assert::true(is_file($this->endpoints->path . '/entered'));
                $old = $sites->find($id);
                $sites->save(array_replace($input, ['url' => $this->server->base . '/app', 'health_path' => '/up', 'enabled' => 0]), $id);
                $sites->save($input, $id);
                Assert::true($sites->find($id)['config_revision'] > $old['config_revision']);
                file_put_contents($this->endpoints->path . '/release', 'release');
                if ($mode === 'worker') { $child->awaitOutput('Worker pass complete.'); $state->requestStop($state->generation()); }
                $result = $child->wait();
                $diagnostic = HealthCheckDiagnostics::capture($result, $this->web->database());
                Assert::same($result['exit_code'], $mode === 'cli' ? 1 : 0, $diagnostic);
                Assert::same(strlen($result['stderr']), 0, $diagnostic);
                Assert::same($sites->find($id)['checked_at'], null, 'ABA response refuses persisted result');
                Assert::same((int) $this->web->database()->query('SELECT count(*) FROM worker_progress WHERE site_id=' . $id)->fetchColumn(), 0, 'no stale service credit');
                $sites->delete($id);
            }
        } finally {
            file_put_contents($this->endpoints->path . '/release', 'release');
            $child?->close();
            unset($sites, $state, $old);
        }
    }
}
